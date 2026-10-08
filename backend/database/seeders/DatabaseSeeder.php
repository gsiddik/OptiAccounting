<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeder: catalogs, permissions and templates only. It never
 * creates tenants, users with default passwords or financial data, and must
 * stay idempotent. Demo data belongs in DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Each phase registers its production-safe seeders here (OA0 onward).
    }
}
