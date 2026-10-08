<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OA0 data scope applied to financial records. A journal is visible when the user has TENANT scope, owns it (OWN), or
 * every one of its lines lies in a branch / business unit of their scope; a ledger line is visible on its own. Anything
 * else is invisible (404, never 403) so a branch-restricted user cannot learn another branch's amounts.
 */
class AccountingScope
{
    /** @var array{tenant:bool,own:bool,branch_ids:list<string>,business_unit_ids:list<string>}|null */
    private ?array $resolved = null;

    public function __construct(private readonly TenantContext $context, private readonly DataScopeService $scopes) {}

    /** @return array{tenant:bool,own:bool,branch_ids:list<string>,business_unit_ids:list<string>} */
    public function scope(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $user = $this->context->user();
        $tenantId = $this->context->tenantId();
        $membership = $user && $tenantId
            ? TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)->first()
            : null;

        return $this->resolved = $membership
            ? $this->scopes->resolve($tenantId, $membership->id)
            : ['tenant' => false, 'own' => false, 'branch_ids' => [], 'business_unit_ids' => []]; // fail closed
    }

    public function isTenantWide(): bool
    {
        return $this->scope()['tenant'];
    }

    public function userId(): ?string
    {
        return $this->context->user()?->id;
    }

    /** May this user put a journal line with these dimensions on a journal created by $creatorId? */
    public function lineAllowed(?string $branchId, ?string $businessUnitId, ?string $creatorId): bool
    {
        $scope = $this->scope();
        if ($scope['tenant'] || ($scope['own'] && $creatorId !== null && $creatorId === $this->userId())) {
            return true;
        }

        return ($branchId !== null && in_array($branchId, $scope['branch_ids'], true))
            || ($businessUnitId !== null && in_array($businessUnitId, $scope['business_unit_ids'], true));
    }

    public function journalVisible(JournalEntry $journal): bool
    {
        $scope = $this->scope();
        if ($scope['tenant'] || ($scope['own'] && $journal->created_by === $this->userId())) {
            return true;
        }

        $lines = DB::table('journal_lines')->where('tenant_id', $journal->tenant_id)->where('journal_entry_id', $journal->id)->get(['branch_id', 'business_unit_id']);

        return $lines->isNotEmpty() && $lines->every(fn ($l) => $this->lineAllowed($l->branch_id, $l->business_unit_id, null));
    }

    /** Restrict a query on journal_entries (the table name is used, not an alias). */
    public function restrictJournals(Builder $query): Builder
    {
        $scope = $this->scope();
        if ($scope['tenant']) {
            return $query;
        }

        return $query->where(function ($where) use ($scope) {
            $where->whereRaw('1 = 0');
            if ($scope['own']) {
                $where->orWhere('journal_entries.created_by', $this->userId());
            }
            if ($scope['branch_ids'] !== [] || $scope['business_unit_ids'] !== []) {
                $branchIn = $scope['branch_ids'] === [] ? 'false' : 'coalesce(l.branch_id in ('.implode(',', array_fill(0, count($scope['branch_ids']), '?')).'), false)';
                $unitIn = $scope['business_unit_ids'] === [] ? 'false' : 'coalesce(l.business_unit_id in ('.implode(',', array_fill(0, count($scope['business_unit_ids']), '?')).'), false)';
                $where->orWhereRaw(
                    "exists (select 1 from journal_lines l where l.tenant_id = journal_entries.tenant_id and l.journal_entry_id = journal_entries.id)
                     and not exists (select 1 from journal_lines l where l.tenant_id = journal_entries.tenant_id and l.journal_entry_id = journal_entries.id
                                     and not ({$branchIn} or {$unitIn}))",
                    [...$scope['branch_ids'], ...$scope['business_unit_ids']],
                );
            }
        });
    }

    /** Restrict a ledger query: lines are visible one by one; OWN means the journal's creator (column `$owner`). */
    public function restrictLines(Builder $query, string $branch = 'jl.branch_id', string $unit = 'jl.business_unit_id', string $owner = 'je.created_by'): Builder
    {
        return $this->scopes->applyToQuery($query, $this->scope(), (string) $this->userId(), ['branch' => $branch, 'business_unit' => $unit, 'owner' => $owner]);
    }
}
