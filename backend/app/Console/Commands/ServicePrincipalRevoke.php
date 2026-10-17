<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\ServicePrincipal;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Shared\DomainException;
use Illuminate\Console\Command;

/** Revokes a service identity for good: the connector that used it stops booking until the operator issues a new one. */
class ServicePrincipalRevoke extends Command
{
    protected $signature = 'optientry:service-principal:revoke
        {principal : Id of the service principal (see optientry:service-principal:list)}
        {--reason= : Why it is revoked (required)}
        {--operator= : Who runs this (default: the operating-system user)}';

    protected $description = 'Revoke a service identity (final; posted history that names it is untouched)';

    public function handle(ServicePrincipalRegistry $registry): int
    {
        $principal = ServicePrincipal::query()->find((string) $this->argument('principal'));
        if ($principal === null) {
            $this->components->error('Unknown service principal.');

            return self::FAILURE;
        }

        try {
            $result = $registry->revoke($principal, (string) $this->option('reason'), null, (string) ($this->option('operator') ?: (getenv('OPTIENTRY_OPERATOR') ?: get_current_user())));
        } catch (DomainException $e) {
            $this->components->error($e->getMessage().' (--reason is required)');

            return self::FAILURE;
        }

        $this->components->info($result['changed'] ? "Revoked {$principal->kind} of tenant {$principal->tenant_id}." : 'Already revoked (nothing changed).');

        return self::SUCCESS;
    }
}
