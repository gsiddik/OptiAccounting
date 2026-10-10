<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Shared\DomainException;

/** Aging bucket definition shared by AP and AR aging: boundaries are data (ascending days past due), the last bucket is open-ended. */
class AgingBuckets
{
    /**
     * @param  list<int>  $boundaries
     * @return list<array{key:string,label:string,from:?int,to:?int}>
     */
    public static function build(array $boundaries): array
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
    public static function keyFor(array $buckets, int $daysOverdue): string
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
