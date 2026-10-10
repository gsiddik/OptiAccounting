<?php

namespace App\Domain\Currency\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Exchange rates into the functional currency. Policy: at most one ACTIVE rate per currency, type and effective date (the database enforces
 * it); a wrong rate is withdrawn (deactivated) and another entered, never edited, so documents that cite a rate keep pointing at what they
 * used. A rate is deleted only while no document cites it. Entering or withdrawing a rate never changes a posted document: documents
 * carry their own snapshot.
 */
class ExchangeRateService
{
    public function __construct(private readonly ExchangeRateResolver $resolver, private readonly AuditService $audit) {}

    public function query(array $filter = []): Builder
    {
        return ExchangeRate::query()
            ->when($filter['currency'] ?? null, fn ($q, $v) => $q->where('from_currency', $v))
            ->when($filter['rate_type'] ?? null, fn ($q, $v) => $q->where('rate_type', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['date_from'] ?? null, fn ($q, $v) => $q->whereDate('effective_date', '>=', $v))
            ->when($filter['date_to'] ?? null, fn ($q, $v) => $q->whereDate('effective_date', '<=', $v))
            ->orderByDesc('effective_date')->orderBy('from_currency')->orderBy('rate_type');
    }

    public function load(ExchangeRate $rate): ExchangeRate
    {
        $rate->setAttribute('in_use', $this->inUse($rate));

        return $rate;
    }

    public function inUse(ExchangeRate $rate): bool
    {
        return DB::table('ap_invoices')->where('exchange_rate_id', $rate->id)->exists()
            || DB::table('ar_invoices')->where('exchange_rate_id', $rate->id)->exists()
            || DB::table('vendor_payments')->where('exchange_rate_id', $rate->id)->exists()
            || DB::table('customer_receipts')->where('exchange_rate_id', $rate->id)->exists();
    }

    public function create(array $data, User $actor): ExchangeRate
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $profile = $this->resolver->profile();
            $from = mb_strtoupper(trim((string) ($data['from_currency'] ?? '')));
            $currency = Currency::query()->where('code', $from)->first()
                ?? throw new DomainException("Currency {$from} is not set up for this tenant.", 'CURRENCY_NOT_FOUND', 422, ['field' => 'from_currency']);
            if ($currency->status !== Currency::ACTIVE) {
                throw new DomainException("Currency {$from} is inactive.", 'CURRENCY_INACTIVE', 422, ['field' => 'from_currency']);
            }
            $type = $data['rate_type'] ?? ExchangeRate::MANUAL;
            if (! in_array($type, ExchangeRate::TYPES, true)) {
                throw new DomainException('The rate type must be SPOT, DAILY, MONTH_END or MANUAL.', 'EXCHANGE_RATE_TYPE_INVALID', 422, ['field' => 'rate_type']);
            }
            $date = $this->date($data['effective_date'] ?? null);

            $rate = new ExchangeRate;
            $rate->forceFill([
                'from_currency' => $from, 'to_currency' => $profile->functional_currency, 'rate' => $this->rateValue($data['rate'] ?? null), 'effective_date' => $date, 'rate_type' => $type,
                'source' => isset($data['source']) ? mb_substr(trim((string) $data['source']), 0, 100) : null, 'notes' => isset($data['notes']) ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
                'status' => ExchangeRate::ACTIVE, 'created_by' => $actor->id,
            ])->save();
            $this->audit->record('exchange_rate.created', 'exchange_rate', $rate->id, null, $this->summary($rate->refresh()));

            return $this->load($rate);
        }));
    }

    /** Only the descriptive fields can change; the rate, currency, date and type are facts. */
    public function update(ExchangeRate $rate, array $data): ExchangeRate
    {
        foreach (['rate', 'from_currency', 'effective_date', 'rate_type'] as $fact) {
            if (array_key_exists($fact, $data)) {
                throw new DomainException('A rate, its currency, date and type cannot be edited; withdraw it and enter another.', 'EXCHANGE_RATE_IMMUTABLE', 409, ['field' => $fact]);
            }
        }

        return DB::transaction(function () use ($rate, $data) {
            $rate = ExchangeRate::query()->lockForUpdate()->findOrFail($rate->id);
            $before = $this->summary($rate);
            $rate->forceFill([
                'source' => array_key_exists('source', $data) ? ($data['source'] === null ? null : mb_substr(trim((string) $data['source']), 0, 100)) : $rate->source,
                'notes' => array_key_exists('notes', $data) ? ($data['notes'] === null ? null : mb_substr(trim((string) $data['notes']), 0, 255)) : $rate->notes,
            ])->save();
            $this->audit->record('exchange_rate.updated', 'exchange_rate', $rate->id, $before, $this->summary($rate->refresh()));

            return $this->load($rate);
        });
    }

    public function setStatus(ExchangeRate $rate, string $status): ExchangeRate
    {
        return $this->guarded(fn () => DB::transaction(function () use ($rate, $status) {
            $rate = ExchangeRate::query()->lockForUpdate()->findOrFail($rate->id);
            if ($rate->status !== $status) {
                $rate->forceFill(['status' => $status])->save();
                $this->audit->record('exchange_rate.status_changed', 'exchange_rate', $rate->id, ['status' => $status === ExchangeRate::ACTIVE ? ExchangeRate::INACTIVE : ExchangeRate::ACTIVE], ['status' => $status] + $this->summary($rate));
            }

            return $this->load($rate);
        }));
    }

    public function delete(ExchangeRate $rate): void
    {
        try {
            DB::transaction(function () use ($rate) {
                $rate = ExchangeRate::query()->lockForUpdate()->findOrFail($rate->id);
                if ($this->inUse($rate)) {
                    throw new DomainException('A document cites this rate; withdraw it instead.', 'EXCHANGE_RATE_IN_USE', 409);
                }
                $summary = $this->summary($rate);
                $rate->delete();
                $this->audit->record('exchange_rate.deleted', 'exchange_rate', $rate->id, $summary, null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('A document cites this rate; withdraw it instead.', 'EXCHANGE_RATE_IN_USE', 409);
            }
            throw $e;
        }
    }

    private function rateValue(mixed $raw): string
    {
        $message = 'The rate is a positive decimal string with at most ten decimals.';
        if ($raw === null || is_bool($raw) || is_array($raw) || is_float($raw) || (is_string($raw) && trim($raw) === '')) {
            throw new DomainException($message, 'EXCHANGE_RATE_INVALID', 422, ['field' => 'rate']);
        }
        $text = trim((string) $raw);
        // a plain decimal only: brick/math would also read "1e3" or "+5", which are never typed as a rate
        if (! preg_match('/^\d+(\.\d+)?$/', $text)) {
            throw new DomainException($message, 'EXCHANGE_RATE_INVALID', 422, ['field' => 'rate']);
        }
        try {
            $rate = BigDecimal::of($text);
        } catch (MathException) {
            throw new DomainException($message, 'EXCHANGE_RATE_INVALID', 422, ['field' => 'rate']);
        }
        if (! $rate->isPositive() || $rate->getScale() > 10 || $rate->isGreaterThanOrEqualTo('1000000000')) {
            throw new DomainException($message, 'EXCHANGE_RATE_INVALID', 422, ['field' => 'rate']);
        }

        return (string) $rate->toScale(10, RoundingMode::Unnecessary);
    }

    private function date(mixed $value): string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_string($value) ? substr($value, 0, 10) : null);
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') !== $value) {
            throw new DomainException('The effective date is required (YYYY-MM-DD).', 'EXCHANGE_RATE_DATE_INVALID', 422, ['field' => 'effective_date']);
        }

        return $value;
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('An active rate of this currency, type and date already exists; withdraw it first to enter another.', 'EXCHANGE_RATE_DUPLICATE', 409);
            }
            throw $e;
        }
    }

    private function summary(ExchangeRate $rate): array
    {
        return ['from_currency' => $rate->from_currency, 'to_currency' => $rate->to_currency, 'rate' => (string) $rate->rate, 'effective_date' => $rate->effective_date->toDateString(), 'rate_type' => $rate->rate_type, 'status' => $rate->status];
    }
}
