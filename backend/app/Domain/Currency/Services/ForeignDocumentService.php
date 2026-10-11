<?php

namespace App\Domain\Currency\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;

/**
 * What a document needs to be entered in a currency other than the functional one, shared by vendor and customer invoices and by payments
 * and receipts: the entitlement, the currency and its precision, the rate for the document's date and the columns that freeze it. A document
 * in the functional currency passes through untouched (rate 1, no snapshot columns), so single-currency tenants never see any of this.
 *
 * The rate is chosen when the document is saved and checked again when it is submitted, approved and posted (`assertCurrent`): a rate
 * entered or withdrawn in between is reported instead of silently ignored. After posting the document keeps its own copy of the rate.
 */
class ForeignDocumentService
{
    public function __construct(private readonly ExchangeRateResolver $resolver, private readonly ActorAuthority $authority) {}

    /**
     * @return array{currency:string,scale:int,rate:ResolvedRate,columns:array<string,mixed>}
     */
    public function prepare(?string $requested, string $rateDate, ?string $rateType, User $actor, AccountingProfile $profile): array
    {
        $code = $requested === null || $requested === '' ? $profile->functional_currency : mb_strtoupper($requested);
        if ($code === $profile->functional_currency) {
            return ['currency' => $code, 'scale' => (int) $profile->currency_scale, 'rate' => ResolvedRate::identity($code, (int) $profile->currency_scale), 'columns' => $this->functionalColumns($code)];
        }

        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_MULTI_CURRENCY', 'EXCHANGE_RATE');
        $currency = $this->resolver->currency($code, $profile);
        $rate = $this->resolver->resolve($code, $rateDate, $rateType, false, $profile);

        return [
            'currency' => $code, 'scale' => $currency['decimal_places'], 'rate' => $rate,
            'columns' => [
                'currency' => $code, 'exchange_rate' => $rate->rateString(), 'exchange_rate_id' => $rate->id, 'exchange_rate_date' => $rate->effectiveDate, 'exchange_rate_type' => $rate->type,
            ],
        ];
    }

    /** The columns of a functional-currency document: no foreign snapshot at all. @return array<string,mixed> */
    public function functionalColumns(string $currency): array
    {
        return ['currency' => $currency, 'exchange_rate' => '1', 'exchange_rate_id' => null, 'exchange_rate_date' => null, 'exchange_rate_type' => null];
    }

    /** True when the stored document is in a foreign currency (it cites a rate). */
    public function isForeign(object $document): bool
    {
        return $document->exchange_rate_id !== null;
    }

    /** A foreign document moves only while the multi-currency module is writable for the tenant (READ_ONLY, SUSPENDED and DISABLED refuse). */
    public function assertWritable(User $actor, object $document): void
    {
        if ($this->isForeign($document)) {
            $this->authority->assertModuleWritable($actor, 'ACCOUNTING_MULTI_CURRENCY', 'EXCHANGE_RATE');
        }
    }

    /**
     * The decimal places a stored document's amounts have: its own currency's for a foreign one, the profile's otherwise.
     */
    public function scaleOf(object $document, AccountingProfile $profile): int
    {
        return $this->isForeign($document) ? $this->resolver->currency($document->currency, $profile)['decimal_places'] : (int) $profile->currency_scale;
    }

    /**
     * The rate a stored document was saved with, confirmed against the master for the document's date (`$lock`: held so it cannot be withdrawn
     * while the document posts). Functional documents resolve to the identity.
     */
    public function current(object $document, string $rateDate, bool $lock = false): ResolvedRate
    {
        if (! $this->isForeign($document)) {
            return ResolvedRate::identity($document->currency, (int) $this->resolver->profile()->currency_scale);
        }

        return $this->resolver->assertCurrent($document->currency, $rateDate, $document->exchange_rate_id, (string) $document->exchange_rate, $document->exchange_rate_type, $lock);
    }

    /** The functional value of a foreign amount at a stored rate: the same arithmetic the document used when it was saved. */
    public function functional(object $document, BigDecimal|string $amount, AccountingProfile $profile): BigDecimal
    {
        $rate = new ResolvedRate($document->currency, $profile->functional_currency, BigDecimal::of((string) $document->exchange_rate), (int) $profile->currency_scale, $document->exchange_rate_id);

        return $this->isForeign($document) ? $rate->convert(BigDecimal::of((string) $amount)) : BigDecimal::of((string) $amount)->toScale(4);
    }

    /** A credit note, a cash transaction or an expense is entered in the functional currency only. */
    public function assertFunctional(?string $requested, AccountingProfile $profile, string $what): void
    {
        if ($requested !== null && $requested !== '' && mb_strtoupper($requested) !== $profile->functional_currency) {
            throw new DomainException("{$what} is entered in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }
    }
}
