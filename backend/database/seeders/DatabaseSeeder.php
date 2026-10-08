<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeder: catalogs, permissions and system roles only. It never
 * creates tenants, users with default passwords or financial data, and stays
 * idempotent. The first platform administrator is created with
 * `php artisan optiaccounting:bootstrap-platform-admin`. Demo data: DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ModuleCatalogSeeder::class,
            AccessControlSeeder::class,
            AccountingCatalogSeeder::class,
        ]);
    }
}
