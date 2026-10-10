<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Shared\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Bounded CSV exports of the OA2 lists: the same query as the list (so the same tenant, scope and filters), capped so a download stays a report, not a data dump. */
final class ListExport
{
    public static function max(): int
    {
        return max(1, (int) config('optientry.export_max_rows', 10000));
    }

    /** @return Collection<int,Model> */
    public static function rows(Builder $query): Collection
    {
        $total = (clone $query)->toBase()->getCountForPagination();
        if ($total > self::max()) {
            throw new DomainException('The export has more than '.self::max().' rows; narrow the filters.', 'EXPORT_TOO_LARGE', 422, ['rows' => $total, 'max_rows' => self::max()]);
        }

        return $query->get();
    }

    public static function number(mixed $value): object
    {
        return CsvExporter::number(Money::str((string) ($value ?? '0')));
    }
}
