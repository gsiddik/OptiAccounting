<?php

namespace App\Domain\Payables\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Payment terms. The due date of a document is derived from its term deterministically (invoice date + days, or end of the
 * invoice month + days); a CUSTOM term has no automatic date, and an explicit due date is accepted only where the term allows it.
 */
class PaymentTermService
{
    /** code => [name, type, days, allows override] — a starting set a tenant may apply, rename or ignore. */
    public const DEFAULTS = [
        'COD' => ['Jatuh tempo saat diterima', PaymentTerm::NET_DAYS, 0, false],
        'NET7' => ['Net 7 hari', PaymentTerm::NET_DAYS, 7, false],
        'NET14' => ['Net 14 hari', PaymentTerm::NET_DAYS, 14, false],
        'NET30' => ['Net 30 hari', PaymentTerm::NET_DAYS, 30, false],
        'NET60' => ['Net 60 hari', PaymentTerm::NET_DAYS, 60, false],
        'EOM30' => ['30 hari setelah akhir bulan', PaymentTerm::END_OF_MONTH, 30, false],
        'CUSTOM' => ['Tanggal jatuh tempo manual', PaymentTerm::CUSTOM, null, true],
    ];

    public function __construct(private readonly AuditService $audit, private readonly TenantContext $context) {}

    public function create(array $data): PaymentTerm
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $term = new PaymentTerm($this->normalize($data));
            $term->status = 'ACTIVE';
            $term->created_by = $this->context->user()?->id;
            $term->save();
            $this->audit->record('payables.payment_term.created', 'payment_term', $term->id, null, $this->summary($term));

            return $term;
        }));
    }

    public function update(PaymentTerm $term, array $data): PaymentTerm
    {
        return $this->guarded(fn () => DB::transaction(function () use ($term, $data) {
            $term = PaymentTerm::query()->lockForUpdate()->findOrFail($term->id);
            $before = $this->summary($term);
            $term->fill($this->normalize($data + $term->only(['term_type', 'due_days', 'allows_due_date_override'])));
            $term->updated_by = $this->context->user()?->id;
            $term->save();
            $this->audit->record('payables.payment_term.updated', 'payment_term', $term->id, $before, $this->summary($term));

            return $term;
        }));
    }

    public function setStatus(PaymentTerm $term, string $status): PaymentTerm
    {
        return DB::transaction(function () use ($term, $status) {
            $term = PaymentTerm::query()->lockForUpdate()->findOrFail($term->id);
            if ($term->status === $status) {
                return $term;
            }
            $before = ['status' => $term->status];
            $term->status = $status;
            $term->updated_by = $this->context->user()?->id;
            $term->save();
            $this->audit->record('payables.payment_term.status_changed', 'payment_term', $term->id, $before, ['status' => $status, 'code' => $term->code]);

            return $term;
        });
    }

    /** A term nobody uses can go; a used one is deactivated (the foreign keys refuse the delete). */
    public function delete(PaymentTerm $term): void
    {
        try {
            DB::transaction(function () use ($term) {
                $term = PaymentTerm::query()->lockForUpdate()->findOrFail($term->id);
                $term->delete();
                $this->audit->record('payables.payment_term.deleted', 'payment_term', $term->id, $this->summary($term), null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This payment term is used by vendors or invoices; deactivate it instead.', 'PAYMENT_TERM_IN_USE', 409);
            }
            throw $e;
        }
    }

    /** Create the standard set for codes the tenant does not have yet. @return list<PaymentTerm> the ones created */
    public function applyDefaults(): array
    {
        return DB::transaction(function () {
            $created = [];
            foreach (self::DEFAULTS as $code => [$name, $type, $days, $override]) {
                if (PaymentTerm::query()->where('code', $code)->exists()) {
                    continue;
                }
                $created[] = $this->create(['code' => $code, 'name' => $name, 'term_type' => $type, 'due_days' => $days, 'allows_due_date_override' => $override]);
            }

            return $created;
        });
    }

    /**
     * The due date a term gives a document dated $baseDate, or the explicit one where the term allows it.
     * Returns [due date, overridden?]. A CUSTOM term (or an override-friendly one) takes the explicit date when given.
     *
     * @return array{0:string,1:bool}
     */
    public function dueDate(?PaymentTerm $term, string $baseDate, ?string $explicit): array
    {
        if ($explicit !== null && $explicit < $baseDate) {
            throw new DomainException('The due date cannot be before the document date.', 'DUE_DATE_INVALID', 422);
        }

        if ($term === null) { // no term: the document date itself unless the user names a date
            return [$explicit ?? $baseDate, $explicit !== null];
        }
        if ($term->status !== 'ACTIVE') {
            throw new DomainException('The payment term is inactive.', 'PAYMENT_TERM_INACTIVE', 422, ['code' => $term->code]);
        }
        if ($term->term_type === PaymentTerm::CUSTOM) {
            return [$explicit ?? throw new DomainException('This payment term needs an explicit due date.', 'DUE_DATE_REQUIRED', 422), true];
        }

        $base = CarbonImmutable::parse($baseDate);
        $derived = ($term->term_type === PaymentTerm::END_OF_MONTH ? $base->endOfMonth() : $base)->addDays((int) $term->due_days)->toDateString();
        if ($explicit !== null && $explicit !== $derived) {
            if (! $term->allows_due_date_override) {
                throw new DomainException('This payment term does not allow a different due date.', 'DUE_DATE_OVERRIDE_NOT_ALLOWED', 422, ['derived_due_date' => $derived]);
            }

            return [$explicit, true];
        }

        return [$derived, false];
    }

    /** @return array<string,mixed> */
    private function normalize(array $data): array
    {
        $type = $data['term_type'] ?? null;
        if (! in_array($type, PaymentTerm::TYPES, true)) {
            throw new DomainException('Unknown payment term type.', 'PAYMENT_TERM_TYPE_INVALID', 422);
        }
        if ($type === PaymentTerm::CUSTOM) {
            $data['due_days'] = null;
            $data['allows_due_date_override'] = true;
        } elseif (! isset($data['due_days']) || (int) $data['due_days'] < 0 || (int) $data['due_days'] > 3650) {
            throw new DomainException('Due days must be between 0 and 3650.', 'PAYMENT_TERM_DAYS_INVALID', 422);
        }
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }

        return collect($data)->only(['code', 'name', 'term_type', 'due_days', 'allows_due_date_override', 'description'])->all();
    }

    private function summary(PaymentTerm $term): array
    {
        return $term->only(['code', 'name', 'term_type', 'due_days', 'allows_due_date_override', 'status']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('A payment term with this code already exists.', 'PAYMENT_TERM_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
