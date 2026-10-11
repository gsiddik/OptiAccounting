<?php

namespace App\Domain\Accounting\Support;

/** `journal_entries.source_type` values that other layers must recognise (closing journals are excluded from period reports). */
final class SourceTypes
{
    /** The year-end closing journal (OA5): a normal immutable posted SYSTEM journal that moves temporary account balances to retained earnings. */
    public const YEAR_END_CLOSING = 'YEAR_END_CLOSING';
}
