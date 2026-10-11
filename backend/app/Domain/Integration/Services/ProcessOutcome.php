<?php

namespace App\Domain\Integration\Services;

/**
 * What an adapter did for one event: the journal it caused (if any) and the traceability links to write. Each link names the source document
 * (`source_type`, `source_id`, optional `source_document_number`) and the target it became: a `journal_id` (defaults to the outcome's journal)
 * and/or a `document_type` + `document_id` of ours (e.g. an AP invoice).
 */
final class ProcessOutcome
{
    /** @param list<array{source_type:string,source_id:string,source_document_number?:?string,journal_id?:?string,document_type?:?string,document_id?:?string}> $links */
    public function __construct(public readonly ?string $journalId = null, public readonly array $links = []) {}
}
