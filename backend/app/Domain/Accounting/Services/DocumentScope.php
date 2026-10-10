<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Shared\DomainException;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * OA0 data scope applied to OA2 documents (invoices, payments, expenses, cash transactions). A document is visible to a user with
 * TENANT scope, to its creator under OWN, and to users whose branch / business unit scope contains its branch / business unit.
 * Anything else is invisible (404, never 403), so a branch-restricted user cannot learn another branch's amounts. A document without
 * a branch or unit is visible to tenant-wide users and its creator only.
 */
class DocumentScope
{
    public function __construct(private readonly AccountingScope $scope, private readonly DataScopeService $scopes) {}

    public function isTenantWide(): bool
    {
        return $this->scope->isTenantWide();
    }

    /** Restrict a query on a table that carries branch_id, business_unit_id and created_by. */
    public function restrict(Builder $query, string $table): Builder
    {
        return $this->scopes->applyToQuery($query, $this->scope->scope(), (string) $this->scope->userId(), [
            'branch' => "{$table}.branch_id", 'business_unit' => "{$table}.business_unit_id", 'owner' => "{$table}.created_by",
        ]);
    }

    public function visible(Model $document): bool
    {
        return $this->scopes->allows($this->scope->scope(), [
            'branch_id' => $document->branch_id, 'business_unit_id' => $document->business_unit_id, 'owner_id' => $document->created_by,
        ], (string) $this->scope->userId());
    }

    /** 404 for a document outside the user's scope. */
    public function authorize(Model $document): Model
    {
        abort_unless($this->visible($document), 404);

        return $document;
    }

    /** May this user put a document in this branch / business unit? (A scoped user must name one inside their scope.) */
    public function assertWritable(?string $branchId, ?string $unitId, ?string $creatorId): void
    {
        if (! $this->scope->lineAllowed($branchId, $unitId, $creatorId)) {
            throw new DomainException('You may only book to the branches and business units in your data scope.', 'DATA_SCOPE_DENIED', 403);
        }
    }
}
