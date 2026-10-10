<?php

namespace App\Domain\Currency\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * The one place a rate is chosen (OA4 §37). Given a transaction currency and a date it returns the rate into the functional currency, or
 * fails: it never assumes 1 for two different currencies. Deterministic: of the active rates effective on or before the date and no older
 * than the allowed age, the one with the latest effective date wins; on the same date the type order MANUAL, SPOT, DAILY, MONTH_END decides
 * unless a type is asked for. A document keeps the id and value of the rate it used and posts with that snapshot, whatever the master says later.
 */
class ExchangeRateResolver
{
    public const MAX_AGE_DAYS = 31;

    public function profile(): AccountingProfile
    {
        return AccountingProfile::query()->first() ?? throw new DomainException('Save the accounting profile first.', 'ACCOUNTING_NOT_CONFIGURED', 409);
    }

    /** The transaction currency of a document: the functional currency, or an active foreign currency of the tenant (decimal places included). */
    public function currency(string $code, ?AccountingProfile $profile = null): array
    {
        $profile ??= $this->profile();
        if ($code === $profile->functional_currency) {
            return ['code' => $code, 'decimal_places' => (int) $profile->currency_scale, 'foreign' => false];
        }
        $currency = Currency::query()->where('code', $code)->first()
            ?? throw new DomainException("Currency {$code} is not set up for this tenant.", 'CURRENCY_NOT_FOUND', 422, ['field' => 'currency', 'currency' => $code]);
        if ($currency->status !== Currency::ACTIVE) {
            throw new DomainException("Currency {$code} is inactive.", 'CURRENCY_INACTIVE', 422, ['field' => 'currency', 'currency' => $code]);
        }

        return ['code' => $code, 'decimal_places' => (int) $currency->decimal_places, 'foreign' => true];
    }

    /** With $lock the rate row is held FOR SHARE until the transaction ends, so it cannot be deactivated while a posting uses it. */
    public function resolve(string $currency, string $date, ?string $type = null, bool $lock = false, ?AccountingProfile $profile = null): ResolvedRate
    {
        $profile ??= $this->profile();
        $scale = (int) $profile->currency_scale;
        if ($currency === $profile->functional_currency) {
            return ResolvedRate::identity($currency, $scale);
        }
        if ($type !== null && ! in_array($type, ExchangeRate::TYPES, true)) {
            throw new DomainException('Unknown exchange rate type.', 'EXCHANGE_RATE_TYPE_INVALID', 422, ['field' => 'exchange_rate_type']);
        }

        $oldest = Carbon::parse($date)->subDays((int) config('optientry.fx_rate_max_age_days', self::MAX_AGE_DAYS))->toDateString();
        $query = ExchangeRate::query()->where('from_currency', $currency)->where('to_currency', $profile->functional_currency)->where('status', ExchangeRate::ACTIVE)
            ->whereDate('effective_date', '<=', $date)->whereDate('effective_date', '>=', $oldest)
            ->when($type, fn ($q, $t) => $q->where('rate_type', $t))
            ->orderByDesc('effective_date')->orderByRaw("array_position(ARRAY['MANUAL','SPOT','DAILY','MONTH_END']::text[], rate_type::text)")->orderBy('id');
        if ($lock) {
            $query->sharedLock();
        }
        $row = $query->first() ?? throw new DomainException(
            "There is no exchange rate from {$currency} to {$profile->functional_currency} for {$date}; enter one before using this currency.",
            'EXCHANGE_RATE_NOT_FOUND', 422, ['currency' => $currency, 'functional_currency' => $profile->functional_currency, 'date' => $date, 'rate_type' => $type],
        );

        return new ResolvedRate($currency, $profile->functional_currency, BigDecimal::of($row->rate), $scale, $row->id, $row->effective_date->toDateString(), $row->rate_type, $row->source);
    }

    /**
     * The rate a draft was saved with is still the rate the master gives for its date. Run when a document is submitted, approved and
     * (with $lock) posted, so a rate entered or withdrawn in between is noticed instead of silently ignored.
     */
    public function assertCurrent(string $currency, string $date, ?string $storedRateId, string $storedRate, ?string $type = null, bool $lock = false): ResolvedRate
    {
        $resolved = $this->resolve($currency, $date, $type, $lock);
        if ($resolved->foreign() && ($resolved->id !== $storedRateId || ! $resolved->rate->isEqualTo(BigDecimal::of($storedRate)))) {
            throw new DomainException('The exchange rate for this document changed after it was saved. Send it back to draft and save it again, or cancel it and enter it again.', 'EXCHANGE_RATE_CHANGED', 409, [
                'currency' => $currency, 'date' => $date, 'saved_rate' => $storedRate, 'current_rate' => $resolved->rateString(),
            ]);
        }

        return $resolved;
    }
}
