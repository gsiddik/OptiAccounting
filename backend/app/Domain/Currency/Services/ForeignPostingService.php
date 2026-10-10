<?php

namespace App\Domain\Currency\Services;

use App\Domain\Accounting\Support\Money;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns the business fact of a foreign-currency document into what the Posting Engine books. The rules and the engine only ever see
 * functional amounts; the foreign amount and the rate travel beside each part as its `transaction` snapshot and end up on the journal line.
 *
 * Rounding is explicit and deterministic: every part is converted on its own (HALF_UP at the functional scale), the control line is the
 * functional total of the document, and the difference between the control line and the sum of the other parts (a few units of the
 * smallest amount at most) is added to the largest net part. A tax part is never adjusted, so the tax a report shows is the tax the
 * ledger holds.
 */
class ForeignPostingService
{
    /**
     * An invoice payload (net / tax / total in the document currency, `distribution` net and tax parts) as functional components and parts.
     * A payload in the functional currency is returned unchanged.
     *
     * @param  array<string,mixed>  $payload
     * @return array{payload:array<string,mixed>,functional_total:BigDecimal}
     */
    public function invoice(array $payload, ResolvedRate $rate): array
    {
        $total = BigDecimal::of($payload['total']);
        if (! $rate->foreign()) {
            return ['payload' => $payload, 'functional_total' => $total];
        }

        $functionalTotal = $rate->convert($total);
        $net = $this->parts($payload['distribution']['net'] ?? [], $rate);
        $taxParts = $payload['distribution']['tax'] ?? [];
        // a manual header tax has no parts of its own: it is one part that takes the rule's default tax account
        if ($taxParts === [] && BigDecimal::of($payload['tax'])->isPositive()) {
            $taxParts = [['amount' => $payload['tax']]];
        }
        $tax = $this->parts($taxParts, $rate);

        $difference = $functionalTotal->minus($this->sum($net))->minus($this->sum($tax));
        if (! $difference->isZero()) {
            $net = $this->adjustLargest($net, $difference);
        }

        $functional = $payload;
        $functional['net'] = Money::str($this->sum($net));
        $functional['tax'] = Money::str($this->sum($tax));
        $functional['total'] = Money::str($functionalTotal);
        $functional['distribution'] = ['net' => $net, 'total' => [['amount' => Money::str($functionalTotal), 'transaction' => $this->leg($rate, $total)]]]
            + ($tax === [] ? [] : ['tax' => $tax]);
        // the snapshot of the rate the posting used, kept with the event so the journal can always be explained
        $functional['fx'] = ['currency' => $rate->currency, 'rate' => $rate->rateString(), 'rate_id' => $rate->id, 'rate_date' => $rate->effectiveDate, 'rate_type' => $rate->type, 'source' => $rate->source];

        return ['payload' => $functional, 'functional_total' => $functionalTotal];
    }

    /** The foreign leg of a journal line. @return array{currency:string,amount:string,rate:string} */
    public function leg(ResolvedRate $rate, BigDecimal $amount): array
    {
        return ['currency' => $rate->currency, 'amount' => Money::str($amount), 'rate' => $rate->rateString()];
    }

    /**
     * Spread $amount (functional) over the allocations in proportion to their foreign amounts, exactly: the parts add up to $amount.
     * Largest remainder at the functional scale, ties to the earlier allocation, so the same input always gives the same split.
     *
     * @param  list<BigDecimal>  $weights
     * @return list<BigDecimal>
     */
    public function spread(BigDecimal $amount, array $weights, int $scale): array
    {
        return Money::prorate($amount, $weights, $scale);
    }

    /**
     * How a foreign payment or receipt settles its invoices, in functional currency. `$functional` is the payment converted at its own
     * (settlement) rate and is spread over the allocations in proportion to their foreign amounts, so the parts add up to it exactly.
     * What an allocation releases from its invoice (`carrying`) is the invoice's remaining functional value in proportion to the
     * foreign amount settled, and all of it when the invoice is settled in full, so an invoice never keeps a residue of rounding.
     * The difference (settlement minus carrying) is the realised exchange difference of the payment; its sign is read by the caller
     * (a payment above the carrying value is a loss for a payable and a gain for a receivable).
     *
     * @param  list<array{foreign:BigDecimal,outstanding:BigDecimal,carrying_left:BigDecimal}>  $allocations  foreign amount applied, foreign amount still open and functional value still carried, per allocation
     * @return array{rows:list<array{carrying:BigDecimal,settlement:BigDecimal}>,carrying:BigDecimal,settlement:BigDecimal,difference:BigDecimal}
     */
    public function settle(BigDecimal $functional, array $allocations, int $scale): array
    {
        $settlements = $this->spread($functional, array_column($allocations, 'foreign'), $scale);
        $rows = [];
        foreach (array_values($allocations) as $i => $allocation) {
            $left = $allocation['carrying_left'];
            if ($allocation['foreign']->isGreaterThanOrEqualTo($allocation['outstanding'])) {
                $carrying = $left;
            } else {
                $carrying = $left->multipliedBy($allocation['foreign'])->dividedBy($allocation['outstanding'], $scale, RoundingMode::HalfUp)->toScale(4);
                if ($carrying->isGreaterThan($left)) {
                    $carrying = $left;
                }
            }
            $rows[] = ['carrying' => $carrying->toScale(4), 'settlement' => $settlements[$i]];
        }
        $carrying = Money::sum(array_column($rows, 'carrying'));
        $settlement = Money::sum(array_column($rows, 'settlement'));

        return ['rows' => $rows, 'carrying' => $carrying, 'settlement' => $settlement, 'difference' => $settlement->minus($carrying)->toScale(4)];
    }

    // ------------------------------------------------------------------------------------------------ internals

    /**
     * @param  list<array<string,mixed>>  $parts
     * @return list<array<string,mixed>>
     */
    private function parts(array $parts, ResolvedRate $rate): array
    {
        $out = [];
        foreach (array_values($parts) as $part) {
            $foreign = BigDecimal::of($part['amount']);
            $functional = $rate->convert($foreign);
            if ($functional->isZero()) {
                continue; // too small to reach the ledger at the functional scale
            }
            $out[] = ['amount' => Money::str($functional), 'transaction' => $this->leg($rate, $foreign)] + array_diff_key($part, ['amount' => true]);
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $parts */
    private function sum(array $parts): BigDecimal
    {
        return Money::sum(array_column($parts, 'amount'));
    }

    /**
     * @param  list<array<string,mixed>>  $parts
     * @return list<array<string,mixed>>
     */
    private function adjustLargest(array $parts, BigDecimal $difference): array
    {
        if ($parts === []) {
            throw new DomainException('The document has no amount that can absorb the rounding of the exchange rate.', 'FX_ROUNDING_UNRESOLVABLE', 422);
        }
        $largest = 0;
        foreach ($parts as $i => $part) {
            if (BigDecimal::of($part['amount'])->isGreaterThan($parts[$largest]['amount'])) {
                $largest = $i;
            }
        }
        $adjusted = BigDecimal::of($parts[$largest]['amount'])->plus($difference);
        if (! $adjusted->isPositive()) {
            throw new DomainException('The rounding of the exchange rate cannot be absorbed by the document lines.', 'FX_ROUNDING_UNRESOLVABLE', 422);
        }
        $parts[$largest]['amount'] = Money::str($adjusted);

        return $parts;
    }
}
