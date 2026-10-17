<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Shared\DomainException;
use Illuminate\Console\Command;

/** The platform operator's way to give a tenant its machine identity (the other one is the platform API). Idempotent. */
class ServicePrincipalIssue extends Command
{
    protected $signature = 'optientry:service-principal:issue
        {tenant : Tenant id or code}
        {kind=OPTIFLEET_INTEGRATION : The kind of service identity}
        {--reason= : Why it is issued (required, kept in the registry and the audit log)}
        {--operator= : Who runs this (default: the operating-system user)}';

    protected $description = 'Issue the service identity (machine account) of a tenant; only the platform operator can, tenants cannot';

    public function handle(ServicePrincipalRegistry $registry): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Tenant::query()->where('code', $ref)->first() ?? (preg_match('/^[0-9a-f-]{36}$/i', $ref) ? Tenant::query()->find($ref) : null);
        if ($tenant === null) {
            $this->components->error("Unknown tenant {$ref}.");

            return self::FAILURE;
        }

        try {
            $result = $registry->issue($tenant, strtoupper((string) $this->argument('kind')), (string) $this->option('reason'), 'CLI', null, $this->operator());
        } catch (DomainException $e) {
            $this->components->error($e->getMessage().($e->errorCode === 'SERVICE_PRINCIPAL_KIND_UNKNOWN' ? '' : ' (--reason is required)'));

            return self::FAILURE;
        }

        $principal = $result['principal'];
        $this->components->info(($result['created'] ? 'Issued' : 'Already issued (nothing changed)').": {$principal->kind} for {$tenant->code}, id {$principal->id}, user {$principal->user_id}");
        $this->line('Known kinds: '.implode(', ', ServicePrincipalKinds::all()));

        return self::SUCCESS;
    }

    private function operator(): string
    {
        return (string) ($this->option('operator') ?: (getenv('OPTIENTRY_OPERATOR') ?: get_current_user()));
    }
}
