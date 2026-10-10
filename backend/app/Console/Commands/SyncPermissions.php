<?php

namespace App\Console\Commands;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\SystemRoleSynchronizer;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Console\Command;

/** Run after a deploy that added permissions: registers them and grants them to the system roles. */
class SyncPermissions extends Command
{
    /** Former name (before the OptiEntry rename); kept so existing cron entries and runbooks keep working. */
    protected $aliases = ['optiaccounting:sync-permissions'];

    protected $signature = 'optientry:sync-permissions';

    protected $description = 'Register new permissions and refresh the system roles of the platform and every tenant';

    public function handle(SystemRoleSynchronizer $roles, AccessCache $cache): int
    {
        $roles->syncRoles();
        $cache->touchPlatform();
        Tenant::query()->pluck('id')->each(fn ($id) => $cache->touchTenant($id));
        $this->info('Permissions and system roles are in sync.');

        return self::SUCCESS;
    }
}
