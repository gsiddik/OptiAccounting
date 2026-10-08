<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Verified OptiNexus claims -> local session. The browser only ever carries a 60 second, single-use ticket;
 * the Sanctum token is issued when the SPA redeems it (the token never travels in a URL).
 */
class SsoLoginService
{
    public function __construct(private readonly AccountLinker $linker) {}

    /**
     * @param  array<string,mixed>  $claims
     * @return string one-time ticket the SPA exchanges for a session token
     *
     * @throws SsoException
     */
    public function ticketFor(array $claims): string
    {
        [$user, $tenant] = $this->linker->link($claims);

        $ticket = Str::random(48);
        Cache::put(
            'optinexus.sso.ticket.'.hash('sha256', $ticket),
            ['user_id' => $user->id, 'tenant_id' => $tenant->id],
            (int) config('optiaccounting.optinexus.sso.ticket_ttl_seconds'),
        );

        return $ticket;
    }

    /**
     * Single use. Re-checks that the user, tenant and membership are still active before a session is issued.
     *
     * @return array{user:User,tenant:Tenant}|null
     */
    public function redeemTicket(string $ticket): ?array
    {
        $data = Cache::pull('optinexus.sso.ticket.'.hash('sha256', $ticket));
        if (! $data) {
            return null;
        }

        $user = User::query()->find($data['user_id']);
        $tenant = Tenant::query()->find($data['tenant_id']);
        $member = $user && $tenant
            ? TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', TenantUser::ACTIVE)->exists()
            : false;

        return ($user?->isActive() && $tenant?->isActive() && $member) ? ['user' => $user, 'tenant' => $tenant] : null;
    }
}
