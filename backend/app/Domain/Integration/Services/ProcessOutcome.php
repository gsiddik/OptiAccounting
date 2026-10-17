<?php

namespace App\Domain\Integration\Services;

/**
 * What an adapter did for one event: the journal it caused (if any) and the traceability links to write. Each link names the source document
 * (`source_type`, `source_id`, optional `source_document_number`) and the target it became: a `journal_id` (defaults to the outcome's journal)
 * and/or a `document_type` + `document_id` of ours (e.g. an AP invoice).
 *
 * `outcome` says what happened to the books, which is not the same as "the event was processed" (ecosystem audit A27): JOURNAL_POSTED (a posted
 * journal exists), AWAITING_APPROVAL (a document waits for an approver), DRAFT_DOCUMENT (a document was prepared and waits for somebody allowed
 * to post it), INFORMATIONAL (the fact is recorded and deliberately books nothing) or NO_EFFECT. Left out, it follows the journal: a journal
 * means JOURNAL_POSTED, none means NO_EFFECT. `documentStatus` is the status of the document at that moment (informational, for display).
 */
final class ProcessOutcome
{
    public const JOURNAL_POSTED = 'JOURNAL_POSTED';

    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    public const DRAFT_DOCUMENT = 'DRAFT_DOCUMENT';

    public const INFORMATIONAL = 'INFORMATIONAL';

    public const NO_EFFECT = 'NO_EFFECT';

    public const OUTCOMES = [self::JOURNAL_POSTED, self::AWAITING_APPROVAL, self::DRAFT_DOCUMENT, self::INFORMATIONAL, self::NO_EFFECT];

    /** @param list<array{source_type:string,source_id:string,source_document_number?:?string,journal_id?:?string,document_type?:?string,document_id?:?string}> $links */
    public function __construct(
        public readonly ?string $journalId = null,
        public readonly array $links = [],
        public readonly ?string $outcome = null,
        public readonly ?string $documentStatus = null,
    ) {}

    public function accountingOutcome(): string
    {
        return $this->outcome ?? ($this->journalId !== null ? self::JOURNAL_POSTED : self::NO_EFFECT);
    }
}
