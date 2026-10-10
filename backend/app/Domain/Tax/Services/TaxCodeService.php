<?php

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Domain\Tax\Models\TaxCode;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxTransaction;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tax configuration: codes, their effective-dated rates and the account they post to. Nothing here is a statutory rule; the tenant
 * enters the codes and rates it needs. A rate is history: a change is a new rate that takes over from a date, the previous one is closed
 * the day before, and a change can never reach back to a date a posted transaction already used. A code that taxed a transaction is
 * deactivated, never deleted, and keeps its type, method and treatment.
 *
 * Every change takes the code row FOR UPDATE; a posting that uses the code holds it FOR SHARE (TaxDocumentService), so a configuration
 * change and a posting never interleave: the posting sees the old configuration whole, or finds the new one and asks for a recalculation.
 */
class TaxCodeService
{
    private const DEFAULT_ROLE = [TaxCode::INPUT_TAX => 'TAX_RECEIVABLE', TaxCode::OUTPUT_TAX => 'TAX_PAYABLE', TaxCode::WITHHOLDING => 'TAX_PAYABLE'];

    private const ACCOUNT_TYPES = [TaxCode::INPUT_TAX => ['ASSET', 'EXPENSE'], TaxCode::OUTPUT_TAX => ['LIABILITY'], TaxCode::WITHHOLDING => ['LIABILITY', 'ASSET'], TaxCode::OTHER => []];

    public function __construct(
        private readonly AccountGuard $accounts,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        return TaxCode::query()->with('account')
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['tax_type'] ?? null, fn ($q, $v) => $q->where('tax_type', $v))
            ->when($filter['direction'] ?? null, fn ($q, $v) => $q->whereIn('tax_type', $v === 'INPUT' ? [TaxCode::INPUT_TAX, TaxCode::OTHER] : [TaxCode::OUTPUT_TAX, TaxCode::OTHER]))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
            })
            ->orderBy('code');
    }

    /** The code with its rates, the rate in force today and whether anything used it. */
    public function load(TaxCode $code): TaxCode
    {
        $code->load(['account', 'rates']);
        $today = Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $current = $code->rates->first(fn ($r) => $r->effective_from->toDateString() <= $today && ($r->effective_until === null || $r->effective_until->toDateString() >= $today));
        $code->setAttribute('current_rate', $current?->rate);
        $code->setAttribute('in_use', $this->inUse($code));

        return $code;
    }

    public function inUse(TaxCode $code): bool
    {
        return TaxTransaction::query()->where('tax_code_id', $code->id)->exists()
            || DB::table('ap_invoice_lines')->where('tax_code_id', $code->id)->exists()
            || DB::table('ar_invoice_lines')->where('tax_code_id', $code->id)->exists()
            || DB::table('expenses')->where('tax_code_id', $code->id)->exists();
    }

    // ------------------------------------------------------------------------------------------------ commands

    public function create(array $data, User $actor): TaxCode
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $columns = $this->prepare($data, null);
            $code = new TaxCode($columns['fillable']);
            $code->forceFill($columns['columns'] + ['status' => TaxCode::ACTIVE, 'created_by' => $actor->id]);
            $code->save();

            $rate = $this->rateValue($code, $data['rate'] ?? '0');
            $from = $this->date($data['effective_from'] ?? null, 'effective_from');
            $this->insertRate($code, $rate, $from, $actor);
            $this->audit->record('tax.code.created', 'tax_code', $code->id, null, $this->summary($code) + ['rate' => $rate->__toString(), 'effective_from' => $from]);

            return $this->load($code->refresh());
        }));
    }

    public function update(TaxCode $code, array $data, User $actor): TaxCode
    {
        return $this->guarded(fn () => DB::transaction(function () use ($code, $data) {
            $code = TaxCode::query()->lockForUpdate()->findOrFail($code->id);
            $before = $this->summary($code);

            $structural = array_intersect(array_keys($data), ['tax_type', 'calculation_method', 'treatment', 'is_recoverable']);
            $changesStructure = false;
            foreach ($structural as $key) {
                $changesStructure = $changesStructure || $data[$key] !== $code->{$key};
            }
            if ($changesStructure && $this->inUse($code)) {
                throw new DomainException('This tax code has been used; its type, method, treatment and recoverability can no longer change. Create a new code instead.', 'TAX_CODE_IN_USE', 409);
            }
            $prepared = $this->prepare($data, $code);
            if ($prepared['columns']['treatment'] !== TaxCode::STANDARD && TaxRate::query()->where('tax_code_id', $code->id)->where('rate', '<>', 0)->exists()) {
                throw new DomainException('A zero rated or exempt code cannot have a rate above zero; remove the code\'s rates or keep it standard.', 'TAX_RATE_INVALID', 422, ['field' => 'treatment']);
            }
            $code->fill($prepared['fillable'])->forceFill($prepared['columns'])->save();
            $after = $this->summary($code->refresh());
            $this->audit->record('tax.code.updated', 'tax_code', $code->id, $before, $after);
            if (($before['account_role'] ?? null) !== ($after['account_role'] ?? null) || ($before['account_id'] ?? null) !== ($after['account_id'] ?? null)) {
                $this->audit->record('tax.mapping.changed', 'tax_code', $code->id, ['account_role' => $before['account_role'], 'account_id' => $before['account_id']], ['account_role' => $after['account_role'], 'account_id' => $after['account_id']]);
            }

            return $this->load($code);
        }));
    }

    public function setStatus(TaxCode $code, string $status): TaxCode
    {
        return DB::transaction(function () use ($code, $status) {
            $code = TaxCode::query()->lockForUpdate()->findOrFail($code->id);
            if ($code->status !== $status) {
                $before = ['status' => $code->status];
                $code->forceFill(['status' => $status])->save();
                $this->audit->record('tax.code.status_changed', 'tax_code', $code->id, $before, ['status' => $status, 'code' => $code->code]);
            }

            return $this->load($code);
        });
    }

    /** A code nobody used is removed with its rates; a used code is deactivated instead. */
    public function delete(TaxCode $code): void
    {
        try {
            DB::transaction(function () use ($code) {
                $code = TaxCode::query()->lockForUpdate()->findOrFail($code->id);
                if ($this->inUse($code)) {
                    throw new DomainException('This tax code has been used; deactivate it instead.', 'TAX_CODE_IN_USE', 409);
                }
                $summary = $this->summary($code);
                TaxRate::query()->where('tax_code_id', $code->id)->delete();
                $code->delete();
                $this->audit->record('tax.code.deleted', 'tax_code', $code->id, $summary, null);
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? '', ['23503', '23514'], true)) {
                throw new DomainException('This tax code has been used; deactivate it instead.', 'TAX_CODE_IN_USE', 409);
            }
            throw $e;
        }
    }

    /**
     * A new rate that takes over from a date. The timeline only moves forward: the new rate must start after the latest existing start; the
     * rate in force is closed the day before. It may not start on or before a date a posted transaction already used.
     */
    public function addRate(TaxCode $code, array $data, User $actor): TaxCode
    {
        return $this->guarded(fn () => DB::transaction(function () use ($code, $data, $actor) {
            $code = TaxCode::query()->lockForUpdate()->findOrFail($code->id);
            $rate = $this->rateValue($code, $data['rate'] ?? null);
            $from = $this->date($data['effective_from'] ?? null, 'effective_from');

            $latest = TaxRate::query()->where('tax_code_id', $code->id)->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->effective_from->toDateString() >= $from) {
                throw new DomainException('A new rate must start after the latest rate of this code ('.$latest->effective_from->toDateString().').', 'TAX_RATE_OVERLAP', 409, ['latest_effective_from' => $latest->effective_from->toDateString()]);
            }
            $used = TaxTransaction::query()->where('tax_code_id', $code->id)->whereIn('status', [TaxTransaction::POSTED, TaxTransaction::REVERSED])->where('tax_date', '>=', $from)->exists();
            if ($used) {
                throw new DomainException('Posted transactions already used this code on or after that date; a rate cannot be changed retroactively.', 'TAX_RATE_RETROACTIVE_CONFLICT', 409, ['effective_from' => $from]);
            }
            $closed = null;
            if ($latest !== null && ($latest->effective_until === null || $latest->effective_until->toDateString() >= $from)) {
                $closed = Carbon::parse($from)->subDay()->toDateString();
                DB::table('tax_rates')->where('id', $latest->id)->update(['effective_until' => $closed, 'updated_at' => now()]);
            }
            $this->insertRate($code, $rate, $from, $actor);
            $code->touch();
            $this->audit->record('tax.rate.added', 'tax_code', $code->id, $latest ? ['rate' => (string) $latest->rate, 'effective_from' => $latest->effective_from->toDateString()] : null, [
                'code' => $code->code, 'rate' => $rate->__toString(), 'effective_from' => $from, 'previous_closed_on' => $closed,
            ]);

            return $this->load($code->refresh());
        }));
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return array{fillable:array<string,mixed>,columns:array<string,mixed>} */
    private function prepare(array $data, ?TaxCode $existing): array
    {
        $pick = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);

        $fillable = [];
        foreach (['name', 'description', 'metadata'] as $key) {
            if (array_key_exists($key, $data)) {
                $fillable[$key] = $key === 'name' ? trim((string) $data[$key]) : $data[$key];
            }
        }
        $columns = [];
        if ($existing === null) {
            $codeValue = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            if ($codeValue === '' || trim((string) ($data['name'] ?? '')) === '') {
                throw new DomainException('The code and name are required.', 'TAX_CODE_INVALID', 422);
            }
            if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $codeValue)) {
                throw new DomainException('The code may contain letters, digits, dot, hyphen and underscore only.', 'TAX_CODE_INVALID', 422, ['field' => 'code']);
            }
            $columns['code'] = $codeValue;
        } elseif (array_key_exists('name', $fillable) && $fillable['name'] === '') {
            throw new DomainException('The name is required.', 'TAX_CODE_INVALID', 422, ['field' => 'name']);
        }

        $type = $pick('tax_type');
        if (! in_array($type, TaxCode::TYPES, true)) {
            throw new DomainException('The tax type must be INPUT_TAX, OUTPUT_TAX, WITHHOLDING or OTHER.', 'TAX_TYPE_INVALID', 422, ['field' => 'tax_type']);
        }
        $method = $pick('calculation_method', TaxCode::EXCLUSIVE);
        if (! in_array($method, [TaxCode::EXCLUSIVE, TaxCode::INCLUSIVE], true)) {
            throw new DomainException('The calculation method must be EXCLUSIVE or INCLUSIVE.', 'TAX_METHOD_INVALID', 422, ['field' => 'calculation_method']);
        }
        $treatment = $pick('treatment', TaxCode::STANDARD);
        if (! in_array($treatment, [TaxCode::STANDARD, TaxCode::ZERO_RATED, TaxCode::EXEMPT], true)) {
            throw new DomainException('The treatment must be STANDARD, ZERO_RATED or EXEMPT.', 'TAX_TREATMENT_INVALID', 422, ['field' => 'treatment']);
        }
        $recoverable = (bool) $pick('is_recoverable', true);
        if (! $recoverable && ! in_array($type, [TaxCode::INPUT_TAX, TaxCode::OTHER], true)) {
            throw new DomainException('Only an input tax (or other) can be non-recoverable; an output tax is always owed.', 'TAX_RECOVERABLE_INVALID', 422, ['field' => 'is_recoverable']);
        }

        // Where the tax posts: a non-recoverable tax is a cost and has no account. Otherwise an explicit account wins, else a role (defaulted by
        // type); naming one of the two in a request replaces the other, so the code never carries a role and an account that disagree.
        $role = $existing?->account_role;
        $accountId = $existing?->account_id;
        if (array_key_exists('account_id', $data)) {
            $accountId = $data['account_id'];
            if (! array_key_exists('account_role', $data) && $accountId !== null) {
                $role = null;
            }
        }
        if (array_key_exists('account_role', $data)) {
            $role = $data['account_role'];
            if (! array_key_exists('account_id', $data) && $role !== null) {
                $accountId = null;
            }
        }
        if ($existing !== null && $type !== $existing->tax_type && ! array_key_exists('account_role', $data) && ! array_key_exists('account_id', $data)) {
            $role = $accountId = null; // a code that changes type takes the default account role of its new type
        }
        if (! $recoverable) {
            $role = $accountId = null;
        } else {
            if ($accountId !== null && ($existing === null || $accountId !== $existing->account_id)) {
                $accountId = $this->accounts->usable($accountId, self::ACCOUNT_TYPES[$type], false, 'account_id')->id;
            }
            if ($role !== null && ($existing === null || $role !== $existing->account_role)) {
                $role = $this->accounts->destinationRole($role, 'account_role');
            }
            if ($role === null && $accountId === null) {
                $role = self::DEFAULT_ROLE[$type] ?? null;
            }
            if ($role === null && $accountId === null) {
                throw new DomainException('Name the account role or account this tax posts to.', 'TAX_ACCOUNT_REQUIRED', 422, ['field' => 'account_role']);
            }
        }

        return [
            'fillable' => $fillable,
            'columns' => $columns + ['tax_type' => $type, 'calculation_method' => $method, 'treatment' => $treatment, 'is_recoverable' => $recoverable, 'account_role' => $role, 'account_id' => $accountId],
        ];
    }

    private function rateValue(TaxCode $code, mixed $raw): BigDecimal
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '') || is_bool($raw) || is_array($raw) || is_float($raw)) {
            throw new DomainException('The rate is a percentage between 0 and 100 given as a decimal string.', 'TAX_RATE_INVALID', 422, ['field' => 'rate']);
        }
        try {
            $rate = BigDecimal::of(trim((string) $raw));
        } catch (MathException) {
            throw new DomainException('The rate is a percentage between 0 and 100 given as a decimal string.', 'TAX_RATE_INVALID', 422, ['field' => 'rate']);
        }
        if ($rate->isNegative() || $rate->isGreaterThan(100) || $rate->getScale() > 6) {
            throw new DomainException('The rate is a percentage between 0 and 100 with at most six decimals.', 'TAX_RATE_INVALID', 422, ['field' => 'rate']);
        }
        if ($code->treatment !== TaxCode::STANDARD && ! $rate->isZero()) {
            throw new DomainException('A zero rated or exempt code has a rate of zero.', 'TAX_RATE_INVALID', 422, ['field' => 'rate']);
        }

        return $rate->toScale(6, RoundingMode::Unnecessary);
    }

    private function date(mixed $value, string $field): string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_string($value) ? substr($value, 0, 10) : null);
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') !== $value) {
            throw new DomainException('The effective date is required (YYYY-MM-DD).', 'TAX_RATE_INVALID', 422, ['field' => $field]);
        }

        return $value;
    }

    private function insertRate(TaxCode $code, BigDecimal $rate, string $from, User $actor): void
    {
        $row = new TaxRate;
        $row->forceFill(['tax_code_id' => $code->id, 'rate' => $rate->__toString(), 'effective_from' => $from, 'effective_until' => null, 'created_by' => $actor->id]);
        $row->save();
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? '';
            if ($sqlState === '23505' && str_contains($e->getMessage(), 'tax_codes_tenant_id_code_unique')) {
                throw new DomainException('A tax code with this code already exists.', 'TAX_CODE_TAKEN', 409, ['field' => 'code']);
            }
            if ($sqlState === '23P01') {
                throw new DomainException('The rate overlaps another rate of this code.', 'TAX_RATE_OVERLAP', 409);
            }
            if ($sqlState === '23514' && str_contains($e->getMessage(), 'already taxed')) {
                throw new DomainException('Posted transactions already used this code on or after that date; a rate cannot be changed retroactively.', 'TAX_RATE_RETROACTIVE_CONFLICT', 409);
            }
            throw $e;
        }
    }

    private function summary(TaxCode $code): array
    {
        return $code->only(['code', 'name', 'tax_type', 'calculation_method', 'treatment', 'is_recoverable', 'account_role', 'account_id', 'status']);
    }
}
