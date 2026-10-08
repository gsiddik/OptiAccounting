<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Identity\Models\User;
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
}
