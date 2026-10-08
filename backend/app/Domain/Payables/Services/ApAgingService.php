<?php

namespace App\Domain\Payables\Services;

use App\Domain\Accounting\Support\Money;
use App\Domain\Shared\DomainException;
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

    public function __construct(private readonly ApSubledgerService $subledger, private readonly \App\Domain\Accounting\Services\DocumentScope $scope) {}

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
            $bucket = $this->bucketFor($buckets, $overdue);
            $amount = BigDecimal::of((string) $row->outstanding_asof);

            $vendors[$row->vendor_id] ??= ['vendor_id' => $row->vendor_id, 'vendor_code' => $row->vendor_code, 'vendor_name' => $row->vendor_name, 'invoice_count' => 0, 'buckets' => $zero, 'total' => BigDecimal::zero()];
            $vendors[$row->vendor_id]['invoice_count']++;
            $vendors[$row->vendor_id]['buckets'][$bucket] = $vendors[$row->vendor_id]['buckets'][$bucket]->plus($amount);
            $vendors[$row->vendor_id]['total'] = $vendors[$row->vendor_id]['total']->plus($amount);
            $totals[$bucket] = $totals[$bucket]->plus($amount);

            if ($detail) {
                $invoices[] = [
                    'id' => $row->id, 'document_number' => $row->document_number, 'vendor_invoice_number' => $row->vendor_invoice_number, 'vendor_id' => $row->vendor_id,
                    'vendor_code' => $row->vendor_code, 'vendor_name' => $row->vendor_name, 'posting_date' => substr((string) $row->posting_date, 0, 10), 'due_date' => substr((string) $row->due_date, 0, 10),
                    'days_overdue' => max($overdue, 0), 'bucket' => $bucket, 'total_amount' => Money::str($row->total_amount),
                    'paid_amount' => Money::str($row->paid_asof), 'outstanding_amount' => Money::str($amount),
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
        $boundaries = array_values(array_unique(array_map('intval', $boundaries)));
        sort($boundaries);
        if ($boundaries === [] || count($boundaries) > 8 || $boundaries[0] < 1 || end($boundaries) > 3650) {
            throw new DomainException('Aging buckets are 1 to 8 ascending day limits between 1 and 3650.', 'AGING_BUCKETS_INVALID', 422);
        }

        $buckets = [['key' => 'CURRENT', 'label' => 'Belum jatuh tempo', 'from' => null, 'to' => 0]];
        $from = 1;
        foreach ($boundaries as $limit) {
            $buckets[] = ['key' => "{$from}-{$limit}", 'label' => "{$from}-{$limit} hari", 'from' => $from, 'to' => $limit];
            $from = $limit + 1;
        }
        $buckets[] = ['key' => ($from - 1).'+', 'label' => '>'.($from - 1).' hari', 'from' => $from, 'to' => null];

        return $buckets;
    }

    /** @param list<array{key:string,from:?int,to:?int}> $buckets */
    private function bucketFor(array $buckets, int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'CURRENT';
        }
        foreach ($buckets as $bucket) {
            if ($bucket['key'] !== 'CURRENT' && $daysOverdue >= $bucket['from'] && ($bucket['to'] === null || $daysOverdue <= $bucket['to'])) {
                return $bucket['key'];
            }
        }

        return $buckets[array_key_last($buckets)]['key'];
    }
}
