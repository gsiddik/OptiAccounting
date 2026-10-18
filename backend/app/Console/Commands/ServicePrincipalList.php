<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\ServicePrincipal;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use Illuminate\Console\Command;

/** What the registry holds, and who still holds a reserved address without being registered (they take a seat). */
class ServicePrincipalList extends Command
{
    protected $signature = 'optientry:service-principal:list {--tenant= : Only this tenant id or code} {--live : Hide revoked principals}';

    protected $description = 'List the service identities of tenants and any unregistered holder of the reserved address range';

    public function handle(ServicePrincipalRegistry $registry): int
    {
        $tenantId = null;
        if ($ref = $this->option('tenant')) {
            $tenantId = Tenant::query()->where('code', $ref)->value('id') ?? (preg_match('/^[0-9a-f-]{36}$/i', (string) $ref) ? $ref : null);
            if ($tenantId === null) {
                $this->components->error("Unknown tenant {$ref}.");

                return self::FAILURE;
            }
        }

        $rows = $registry->list($tenantId, $this->option('live') ? true : null)->map(fn (ServicePrincipal $p) => [
            $p->id, $p->tenant?->code, $p->kind, $p->user?->email, $p->isRevoked() ? 'REVOKED' : 'LIVE', $p->issued_at?->toDateTimeString(), $p->issued_via, $p->issued_by_label,
        ])->all();
        $this->table(['id', 'tenant', 'kind', 'user', 'status', 'issued at', 'via', 'by'], $rows);

        $strays = $registry->unregisteredReservedUsers();
        if ($strays->isNotEmpty()) {
            $this->components->warn($strays->count().' account(s) hold an address of the reserved range without being registered. They are treated as people and take a seat: '.$strays->pluck('email')->implode(', '));
        }

        return self::SUCCESS;
    }
}
