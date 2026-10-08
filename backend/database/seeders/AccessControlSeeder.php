<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Services\SystemRoleSynchronizer;
use Illuminate\Database\Seeder;

/** Permission registry and system roles (bootstrap data only; no authorization depends on role names). */
class AccessControlSeeder extends Seeder
{
    public function run(SystemRoleSynchronizer $synchronizer): void
    {
        $synchronizer->syncRoles();
    }
}
