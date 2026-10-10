<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_database_and_identity_mode(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'service' => 'optientry-api',
                'api_version' => 'v1',
                'identity_mode' => 'standalone',
                'checks' => ['database' => 'ok'],
            ]);
    }

    public function test_health_runs_against_postgresql(): void
    {
        // Accounting invariants rely on PostgreSQL features (NUMERIC, triggers,
        // row locks); the suite must never silently fall back to SQLite.
        $this->assertSame('pgsql', config('database.default'));
    }
}
