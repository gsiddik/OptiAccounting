<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;

/** "May this user do X?" for rules that depend on a second permission (soft-closed posting, duplicate override). The resolver stays the only authority. */
class ActorAuthority
{
    public function __construct(private readonly EffectiveAccess $access, private readonly TenantContext $context) {}

    public function can(User $actor, string $permission): bool
    {
        return $this->access->evaluate(new AccessRequest($actor, $this->context->tenantId(), permission: $permission))->allowed;
    }

    public function canPostSoftClosed(User $actor): bool
    {
        return $this->can($actor, 'accounting.journal.post_soft_closed');
    }

    /**
     * A document that also touches another module (a payment draws on a cash account, an expense creates a payable) needs that module to be
     * available for writing too: entitled, not READ_ONLY, with its parent modules active. Refuses with the reason the resolver gave.
     */
    public function assertModuleWritable(User $actor, string $module, ?string $feature = null): void
    {
        $decision = $this->access->evaluate(new AccessRequest($actor, $this->context->tenantId(), module: $module, feature: $feature, mutating: true));
        if (! $decision->allowed) {
            throw new DomainException("This needs the {$module} module to be available ({$decision->code}).", 'MODULE_NOT_AVAILABLE', 403, ['module' => $module, 'reason' => $decision->code]);
        }
    }

    /**
     * Every OA2 document that posts or reverses a journal needs the core ledger to be writable: ACCOUNTING_CORE (the parent of every OA2 module)
     * entitled and not READ_ONLY, with the journal feature on. A child module that stays ACTIVE cannot write to a ledger the tenant has lost.
     */
    public function assertLedgerWritable(User $actor): void
    {
        $this->assertModuleWritable($actor, 'ACCOUNTING_CORE', 'JOURNAL');
    }
}
