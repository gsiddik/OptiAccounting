<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data (demo tenant, users, sample transactions) for local and showcase
 * environments only. Logins it creates are documented in docs/DEMO.md.
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        // Each phase adds its demo data here (OA0 onward).
    }
}
