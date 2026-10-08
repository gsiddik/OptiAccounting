<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy-driven maker-checker (flags on the accounting profile; no role names). It guards manual journals only:
 * reversal, opening and event journals are controlled by their own permissions. These rules never touch the
 * double-entry invariants, which hold whatever the policy says.
 */
class SegregationOfDuties
{
    public function assertMayApprove(JournalEntry $journal, string $actorId, AccountingProfile $profile): void
    {
        if ($profile->sod_creator_not_approver && ($journal->created_by === $actorId || $journal->submitted_by === $actorId)) {
            throw new DomainException('Segregation of duties: the person who prepared a journal cannot approve it.', 'SOD_VIOLATION', 403, ['rule' => 'creator_not_approver']);
        }
    }

    public function assertMayPost(JournalEntry $journal, string $actorId, AccountingProfile $profile): void
    {
        if (! in_array($journal->journal_type, [JournalEntry::MANUAL, JournalEntry::OPENING], true)) {
            return; // system-authority journals have no human preparer
        }
        if ($profile->sod_creator_not_poster && $journal->created_by === $actorId) {
            throw new DomainException('Segregation of duties: the person who prepared a journal cannot post it.', 'SOD_VIOLATION', 403, ['rule' => 'creator_not_poster']);
        }
        if ($profile->sod_approver_not_poster && $journal->approved_by === $actorId) {
            throw new DomainException('Segregation of duties: the person who approved a journal cannot post it.', 'SOD_VIOLATION', 403, ['rule' => 'approver_not_poster']);
        }
    }

    /** What the actor may still do with this journal under the current policy (the UI uses it to show or hide buttons). */
    public function allowed(JournalEntry $journal, string $actorId, AccountingProfile $profile): array
    {
        $can = function (callable $check): bool {
            try {
                $check();

                return true;
            } catch (DomainException) {
                return false;
            }
        };

        return [
            'approve' => $can(fn () => $this->assertMayApprove($journal, $actorId, $profile)),
            'post' => $can(fn () => $this->assertMayPost($journal, $actorId, $profile)),
        ];
    }

    // ------------------------------------------------------------------------------------------ OA2 source documents

    /**
     * The same policy flags guard vendor invoices, vendor payments, expenses and cash transactions: whoever prepared (or submitted)
     * a document cannot approve it, and, where the profile says so, cannot post it, nor can its approver. Never role names.
     *
     * @param  Model  $document  any row with created_by / submitted_by / approved_by
     */
    public function assertDocumentMayApprove(Model $document, string $actorId, AccountingProfile $profile): void
    {
        if ($profile->sod_creator_not_approver && ($document->created_by === $actorId || $document->submitted_by === $actorId)) {
            throw new DomainException('Segregation of duties: the person who prepared a document cannot approve it.', 'SOD_VIOLATION', 403, ['rule' => 'creator_not_approver']);
        }
    }

    public function assertDocumentMayPost(Model $document, string $actorId, AccountingProfile $profile): void
    {
        if ($profile->sod_creator_not_poster && $document->created_by === $actorId) {
            throw new DomainException('Segregation of duties: the person who prepared a document cannot post it.', 'SOD_VIOLATION', 403, ['rule' => 'creator_not_poster']);
        }
        if ($profile->sod_approver_not_poster && $document->approved_by === $actorId) {
            throw new DomainException('Segregation of duties: the person who approved a document cannot post it.', 'SOD_VIOLATION', 403, ['rule' => 'approver_not_poster']);
        }
    }

    /** @return array{approve:bool,post:bool} what the actor may still do with this document (the UI shows or hides buttons from it) */
    public function documentAllowed(Model $document, string $actorId, AccountingProfile $profile): array
    {
        $can = function (callable $check): bool {
            try {
                $check();

                return true;
            } catch (DomainException) {
                return false;
            }
        };

        return [
            'approve' => $can(fn () => $this->assertDocumentMayApprove($document, $actorId, $profile)),
            'post' => $can(fn () => $this->assertDocumentMayPost($document, $actorId, $profile)),
        ];
    }
}
