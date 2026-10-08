<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Catalog, permissions and system roles are production seed data; every test starts from them. */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;
}
