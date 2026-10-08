<?php

namespace App\Domain\Accounting\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Central, reusable document numbering. One row per (tenant, sequence, scope); the next value is taken with a single
 * INSERT ... ON CONFLICT DO UPDATE ... RETURNING, so concurrent transactions queue on the row lock and a rolled-back
 * posting gives its number back (no gaps, no MAX()+1). Call it inside the transaction that uses the number.
 */
class DocumentNumbering
{
    public function __construct(private readonly TenantContext $context) {}

    /** e.g. issue('JOURNAL.MANUAL', $fiscalYearId, 'JV', 'FY2026') => "JV-FY2026-000001" */
    public function issue(string $sequenceCode, string $scopeKey, string $prefix, string $scopeLabel): string
    {
        $tenantId = $this->context->tenantId() ?? throw new \LogicException('Numbering requires a tenant context.');

        $value = DB::selectOne(
            'insert into document_sequences (id, tenant_id, sequence_code, scope_key, prefix, last_value, created_at, updated_at)
             values (?, ?, ?, ?, ?, 1, now(), now())
             on conflict (tenant_id, sequence_code, scope_key)
             do update set last_value = document_sequences.last_value + 1, updated_at = now()
             returning last_value',
            [(string) Str::uuid7(), $tenantId, $sequenceCode, $scopeKey, $prefix],
        )->last_value;

        return sprintf('%s-%s-%06d', $prefix, $scopeLabel, $value);
    }
}
