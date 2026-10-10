<?php

namespace App\Support\Database;

/**
 * "Now" for columns that order history (audit_logs, document_transitions, journal_transitions). The query builder binds a Carbon value with the
 * grammar's date format (whole seconds), which silently dropped the microseconds those columns were widened for: events of one second had no
 * defined order. A string keeps them.
 */
final class Micros
{
    public static function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }
}
