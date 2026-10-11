<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\AgingBuckets;
use App\Domain\Accounting\Support\Money;
use Brick\Math\BigDecimal;

/**
 * AR aging: what is still outstanding on a chosen as-of date, grouped by how far past its due date it is on that date. The bucket
 * boundaries are data (days past due, ascending): [30, 60, 90] gives Current, 1-30, 31-60, 61-90 and 90+. The figures come from the
 * subledger replay for that date (ArSubledgerService::rowsAsOf), so a past report never changes when later receipts or credit notes are posted, and the
 * data scope limits the rows (`complete` says whether the report covers the whole ledger).
 */
class ArAgingService
{
    public const DEFAULT_BOUNDARIES = [30, 60, 90];

    public function __construct(private readonly ArSubledgerService $subledger, private readonly DocumentScope $scope) {}

    /**
     * @param  list<int>|null  $boundaries
     * @param  array{customer_id?:?string,branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $filter
     * @return array<string,mixed>
     */
    public function report(string $asOf, ?array $boundaries = null, array $filter = [], bool $detail = false): array
    {
        $buckets = $this->buckets($boundaries ?? self::DEFAULT_BOUNDARIES);
        $rows = $this->subledger->rowsAsOf($asOf, $filter);

        $zero = array_fill_keys(array_column($buckets, 'key'), BigDecimal::zero());
        $customers = [];
        $totals = $zero;
        $invoices = [];
        foreach ($rows as $row) {
            $overdue = (int) round((strtotime($asOf) - strtotime((string) $row->due_date)) / 86400);
            $bucket = AgingBuckets::keyFor($buckets, $overdue);
            // buckets and totals are in the functional currency; a foreign invoice also shows its own amount and rate in the detail
            $amount = BigDecimal::of((string) $row->outstanding_functional_asof);

            $customers[$row->customer_id] ??= ['customer_id' => $row->customer_id, 'customer_code' => $row->customer_code, 'customer_name' => $row->customer_name, 'invoice_count' => 0, 'buckets' => $zero, 'total' => BigDecimal::zero()];
            $customers[$row->customer_id]['invoice_count']++;
            $customers[$row->customer_id]['buckets'][$bucket] = $customers[$row->customer_id]['buckets'][$bucket]->plus($amount);
            $customers[$row->customer_id]['total'] = $customers[$row->customer_id]['total']->plus($amount);
            $totals[$bucket] = $totals[$bucket]->plus($amount);

            if ($detail) {
                $invoices[] = [
                    'id' => $row->id, 'document_number' => $row->document_number, 'customer_reference' => $row->customer_reference, 'customer_id' => $row->customer_id,
                    'customer_code' => $row->customer_code, 'customer_name' => $row->customer_name, 'posting_date' => substr((string) $row->posting_date, 0, 10), 'due_date' => substr((string) $row->due_date, 0, 10),
                    'days_overdue' => max($overdue, 0), 'bucket' => $bucket, 'total_amount' => Money::str($row->total_amount),
                    'received_amount' => Money::str($row->received_asof), 'credited_amount' => Money::str($row->credited_asof), 'outstanding_amount' => Money::str($row->outstanding_asof),
                    'currency' => $row->currency, 'exchange_rate' => (string) $row->exchange_rate, 'outstanding_functional' => Money::str($amount),
                ];
            }
        }

        $format = fn (array $b) => array_map(fn (BigDecimal $v) => Money::str($v), $b);
        $grand = Money::sum(array_values($totals));

        return [
            'as_of' => $asOf,
            'buckets' => $buckets,
            'data' => array_values(array_map(fn (array $v) => ['buckets' => $format($v['buckets']), 'total' => Money::str($v['total'])] + $v, $customers)),
            'totals' => $format($totals) + ['total' => Money::str($grand)],
            'invoice_count' => $rows->count(),
            'complete' => $this->scope->isTenantWide(),
        ] + ($detail ? ['invoices' => $invoices] : []);
    }

    /**
     * @param  list<int>  $boundaries
     * @return list<array{key:string,label:string,from:?int,to:?int}>
     */
    public function buckets(array $boundaries): array
    {
        return AgingBuckets::build($boundaries);
    }
}
