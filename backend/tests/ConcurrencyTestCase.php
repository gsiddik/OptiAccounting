<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

/**
 * Base for tests that need several database connections at once. Rows must be committed to be visible to another
 * connection, so these tests cannot run inside the rolled-back transaction of RefreshDatabase: the tables are truncated
 * before each test and, to keep the shared test database in the state the transaction-based tests expect, truncated and
 * seeded again afterwards.
 */
abstract class ConcurrencyTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->truncateDatabaseTables();
        }
        parent::tearDown();
    }

    /** A second, independent connection to the test database (its own session, locks and transaction). */
    protected function rawConnection(): PDO
    {
        $c = config('database.connections.'.config('database.default'));
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("set application_name = 'concurrency-test-holder'");

        return $pdo;
    }
}
