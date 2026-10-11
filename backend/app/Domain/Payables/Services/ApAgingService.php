<?php

namespace App\Domain\Payables\Services;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\AgingBuckets;
use App\Domain\Accounting\Support\Money;
use Brick\Math\BigDecimal;

/**
 * AP aging: what is still outstanding on a chosen as-of date, grouped by how far past its due date it is on that date. The bucket
 * boundaries are data (days past due, ascending): [30, 60, 90] gives Current, 1-30, 31-60, 61-90 and 90+. The figures come from the
 * subledger replay for that date (ApSubledgerService::rowsAsOf), so a past report never changes when later payments are made, and the
 * data scope limits the rows (`complete` says whether the report covers the whole ledger).
 */
class ApAgingService
{
    public const DEFAULT_BOUNDARIES = [30, 60, 90];

    public function __construct(private readonly ApSubledgerService $subledger, private readonly DocumentScope $scope) {}

    /**
     * @param  list<int>|null  $boundaries
     * @param  array{vendor_id?:?string,branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $filter
     * @return array<string,mixed>
     */
    public function report(string $asOf, ?array $boundaries = null, array $filter = [], bool $detail = false): array
    {
        $buckets = $this->buckets($boundaries ?? self::DEFAULT_BOUNDARIES);
        $rows = $this->subledger->rowsAsOf($asOf, $filter);

        $zero = array_fill_keys(array_column($buckets, 'key'), BigDecimal::zero());
        $vendors = [];
        $totals = $zero;
        $invoices = [];
        foreach ($rows as $row) {
            $overdue = (int) round((strtotime($asOf) - strtotime((string) $row->due_date)) / 86400);
            $bucket = AgingBuckets::keyFor($buckets, $overdue);
            $amount = BigDecimal::of((string) $row->outstanding_functional_asof); // buckets and totals are in functional currency, the one the control account is kept in

            $vendors[$row->vendor_id] ??= ['vendor_id' => $row->vendor_id, 'vendor_code' => $row->vendor_code, 'vendor_name' => $row->vendor_name, 'invoice_count' => 0, 'buckets' => $zero, 'total' => BigDecimal::zero()];
            $vendors[$row->vendor_id]['invoice_count']++;
            $vendors[$row->vendor_id]['buckets'][$bucket] = $vendors[$row->vendor_id]['buckets'][$bucket]->plus($amount);
            $vendors[$row->vendor_id]['total'] = $vendors[$row->vendor_id]['total']->plus($amount);
            $totals[$bucket] = $totals[$bucket]->plus($amount);

            if ($detail) {
                $invoices[] = [
                    'id' => $row->id, 'document_number' => $row->document_number, 'vendor_invoice_number' => $row->vendor_invoice_number, 'vendor_id' => $row->vendor_id,
                    'vendor_code' => $row->vendor_code, 'vendor_name' => $row->vendor_name, 'posting_date' => substr((string) $row->posting_date, 0, 10), 'due_date' => substr((string) $row->due_date, 0, 10),
                    'days_overdue' => max($overdue, 0), 'bucket' => $bucket, 'currency' => $row->currency, 'exchange_rate' => (string) $row->exchange_rate, 'total_amount' => Money::str($row->total_amount),
                    'paid_amount' => Money::str($row->paid_asof), 'outstanding_amount' => Money::str($row->outstanding_asof), 'outstanding_functional' => Money::str($amount),
                ];
            }
        }

        $format = fn (array $b) => array_map(fn (BigDecimal $v) => Money::str($v), $b);
        $grand = Money::sum(array_values($totals));

        return [
            'as_of' => $asOf,
            'buckets' => $buckets,
            'data' => array_values(array_map(fn (array $v) => ['buckets' => $format($v['buckets']), 'total' => Money::str($v['total'])] + $v, $vendors)),
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
