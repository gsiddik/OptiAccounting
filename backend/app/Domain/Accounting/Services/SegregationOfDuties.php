<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\DomainException;

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
        if ($journal->journal_type !== JournalEntry::MANUAL) {
            return;
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
}
