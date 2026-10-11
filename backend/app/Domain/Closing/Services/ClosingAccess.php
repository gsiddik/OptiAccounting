<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;

/**
 * The in-service authorization of every closing action: the permission through the central resolver, a writable ACCOUNTING_REPORTING module and a
 * writable core ledger (a child module that stays ACTIVE cannot close the periods of a ledger the tenant has lost). The routes carry the same gate; the services repeat it because the legacy period routes are gated on ACCOUNTING_CORE only and a
 * service can be reached from more than one route.
 */
class ClosingAccess
{
    public function __construct(private readonly ActorAuthority $authority, private readonly TenantContext $context) {}

    public function user(): User
    {
        return $this->context->user() ?? throw new DomainException('Closing needs a signed-in user.', 'UNAUTHENTICATED', 401);
    }

    public function authorize(string $permission): User
    {
        $user = $this->user();
        $this->require($user, $permission);
        $this->authority->assertModuleWritable($user, 'ACCOUNTING_REPORTING', 'PERIOD_CLOSING');
        $this->authority->assertModuleWritable($user, 'ACCOUNTING_CORE', 'ACCOUNTING_CONFIGURATION'); // closing changes the periods of the core ledger

        return $user;
    }

    /** A further permission an action needs only in some situations (the year-end reversal that must reopen a period). */
    public function require(User $user, string $permission): void
    {
        if (! $this->authority->can($user, $permission)) {
            throw new DomainException("This action needs the permission {$permission}.", 'PERMISSION_DENIED', 403, ['permission' => $permission]);
        }
    }

    public function canPostSoftClosed(User $user): bool
    {
        return $this->authority->canPostSoftClosed($user);
    }
}
