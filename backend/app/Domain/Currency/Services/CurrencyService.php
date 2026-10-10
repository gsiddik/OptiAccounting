<?php

namespace App\Domain\Currency\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The foreign currencies a tenant uses. Identity is the three-letter ISO code. A currency that rates or documents already use keeps its code
 * and precision; it is deactivated, never deleted. The functional currency is not a row here: it is always available.
 */
class CurrencyService
{
    public function __construct(private readonly ExchangeRateResolver $resolver, private readonly AuditService $audit, private readonly TenantContext $context) {}

    public function query(array $filter = []): Builder
    {
        return Currency::query()
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
            })
            ->orderBy('code');
    }

    public function load(Currency $currency): Currency
    {
        $currency->setAttribute('in_use', $this->inUse($currency));

        return $currency;
    }

    public function inUse(Currency $currency): bool
    {
        return ExchangeRate::query()->where('from_currency', $currency->code)->exists()
            || DB::table('ap_invoices')->where('tenant_id', $currency->tenant_id)->where('currency', $currency->code)->exists()
            || DB::table('ar_invoices')->where('tenant_id', $currency->tenant_id)->where('currency', $currency->code)->exists();
    }

    public function create(array $data, User $actor): Currency
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            $profile = $this->resolver->profile();
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                throw new DomainException('A currency is identified by its three-letter ISO 4217 code.', 'CURRENCY_CODE_INVALID', 422, ['field' => 'code']);
            }
            if ($code === $profile->functional_currency) {
                throw new DomainException('The functional currency is always available and needs no setup.', 'CURRENCY_IS_FUNCTIONAL', 422, ['field' => 'code']);
            }
            $places = $this->places($data['decimal_places'] ?? 2);

            $currency = new Currency(['name' => trim((string) ($data['name'] ?? '')) ?: throw new DomainException('The currency name is required.', 'CURRENCY_NAME_REQUIRED', 422, ['field' => 'name']), 'symbol' => $data['symbol'] ?? null]);
            $currency->forceFill(['code' => $code, 'decimal_places' => $places, 'status' => Currency::ACTIVE, 'created_by' => $actor->id])->save();
            $this->audit->record('currency.created', 'currency', $currency->id, null, $this->summary($currency));

            return $this->load($currency->refresh());
        }));
    }

    public function update(Currency $currency, array $data): Currency
    {
        return DB::transaction(function () use ($currency, $data) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
            $before = $this->summary($currency);
            if (array_key_exists('decimal_places', $data)) {
                $places = $this->places($data['decimal_places']);
                if ($places !== (int) $currency->decimal_places && $this->inUse($currency)) {
                    throw new DomainException('This currency has been used; its precision can no longer change.', 'CURRENCY_IN_USE', 409);
                }
                $currency->forceFill(['decimal_places' => $places]);
            }
            $currency->fill(array_intersect_key($data, array_flip(['name', 'symbol'])));
            if (trim((string) $currency->name) === '') {
                throw new DomainException('The currency name is required.', 'CURRENCY_NAME_REQUIRED', 422, ['field' => 'name']);
            }
            $currency->save();
            $this->audit->record('currency.updated', 'currency', $currency->id, $before, $this->summary($currency->refresh()));

            return $this->load($currency);
        });
    }

    public function setStatus(Currency $currency, string $status): Currency
    {
        return DB::transaction(function () use ($currency, $status) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
            if ($currency->status !== $status) {
                $currency->forceFill(['status' => $status])->save();
                $this->audit->record('currency.status_changed', 'currency', $currency->id, ['status' => $status === Currency::ACTIVE ? Currency::INACTIVE : Currency::ACTIVE], ['status' => $status, 'code' => $currency->code]);
            }

            return $this->load($currency);
        });
    }

    public function delete(Currency $currency): void
    {
        try {
            DB::transaction(function () use ($currency) {
                $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
                if ($this->inUse($currency)) {
                    throw new DomainException('This currency has been used; deactivate it instead.', 'CURRENCY_IN_USE', 409);
                }
                $summary = $this->summary($currency);
                $currency->delete();
                $this->audit->record('currency.deleted', 'currency', $currency->id, $summary, null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This currency has been used; deactivate it instead.', 'CURRENCY_IN_USE', 409);
            }
            throw $e;
        }
    }

    private function places(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new DomainException('The decimal places are a whole number from 0 to 4.', 'CURRENCY_PRECISION_INVALID', 422, ['field' => 'decimal_places']);
        }
        $places = (int) $value;
        if ($places < 0 || $places > 4) {
            throw new DomainException('The decimal places are a whole number from 0 to 4.', 'CURRENCY_PRECISION_INVALID', 422, ['field' => 'decimal_places']);
        }

        return $places;
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('This currency is already set up.', 'CURRENCY_TAKEN', 409, ['field' => 'code']);
            }
            throw $e;
        }
    }

    private function summary(Currency $currency): array
    {
        return $currency->only(['code', 'name', 'symbol', 'decimal_places', 'status']);
    }
}
