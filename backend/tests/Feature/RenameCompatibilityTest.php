<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\OutboxPublisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The product was renamed from OptiAccounting to OptiEntry. What operators already have (environment files, cron entries,
 * runbooks) and what is registered elsewhere keeps working; docs/status/RENAME_OPTIENTRY.md lists each case.
 */
class RenameCompatibilityTest extends TestCase
{
    /** @var array<string, string|null> the environment as the suite found it, restored after each test */
    private array $original = [];

    /** Sets (or, with null, removes) a variable where Laravel's env() looks: $_ENV, $_SERVER and the process environment. */
    private function setEnv(string $name, ?string $value): void
    {
        $this->original[$name] ??= $_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) === false ? null : getenv($name));
        $this->applyEnv($name, $value);
    }

    private function applyEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            $this->applyEnv($name, $value);
        }
        parent::tearDown();
    }

    /** The config file as it would load for the current environment. */
    private function loadConfig(): array
    {
        return require config_path('optientry.php');
    }

    public function test_an_existing_env_file_with_the_old_variable_names_still_configures_the_application(): void
    {
        $this->setEnv('OPTIENTRY_IDENTITY_MODE', null);
        $this->setEnv('OPTIACCOUNTING_IDENTITY_MODE', 'optinexus');
        $this->setEnv('OPTIACCOUNTING_DEMO_PASSWORD', 'Old#Demo2026');
        $this->setEnv('OPTIACCOUNTING_EXPORT_MAX_ROWS', '77');

        $config = $this->loadConfig();

        $this->assertSame('optinexus', $config['identity_mode']);
        $this->assertSame('Old#Demo2026', $config['demo_password']);
        $this->assertSame(77, $config['export_max_rows']);
    }

    public function test_the_new_variable_names_win_when_both_are_set(): void
    {
        $this->setEnv('OPTIACCOUNTING_IDENTITY_MODE', 'optinexus');
        $this->setEnv('OPTIENTRY_IDENTITY_MODE', 'standalone');
        $this->setEnv('OPTIACCOUNTING_EXPORT_MAX_ROWS', '77');
        $this->setEnv('OPTIENTRY_EXPORT_MAX_ROWS', '88');

        $config = $this->loadConfig();

        $this->assertSame('standalone', $config['identity_mode']);
        $this->assertSame(88, $config['export_max_rows']);
    }

    public function test_every_former_artisan_name_still_runs_the_renamed_command(): void
    {
        $former = ['optiaccounting:bootstrap-platform-admin', 'optiaccounting:sync-permissions', 'optiaccounting:nexus:manifest',
            'optiaccounting:nexus:check', 'optiaccounting:nexus:sync-entitlements', 'optiaccounting:nexus:relay-events'];
        $commands = Artisan::all();

        foreach ($former as $name) {
            $this->assertArrayHasKey($name, $commands, "{$name} must stay available as an alias");
            $this->assertSame(str_replace('optiaccounting:', 'optientry:', $name), $commands[$name]->getName());
        }
        $this->assertSame([], array_filter(array_keys($commands), fn ($n) => str_starts_with($n, 'optiaccounting:') && ! in_array($n, $former, true)));

        $this->artisan('optiaccounting:sync-permissions')->assertSuccessful();
    }

    public function test_bootstrap_still_reads_the_password_from_the_old_variable(): void
    {
        $this->setEnv('OPTIENTRY_BOOTSTRAP_PASSWORD', null);
        $this->setEnv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD', 'Str0ng!Passw0rd#2026');

        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'legacy@example.test'])->assertExitCode(0);

        $this->assertTrue(User::query()->where('email', 'legacy@example.test')->exists());
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', User::query()->where('email', 'legacy@example.test')->value('id'))->count());
    }

    public function test_identifiers_registered_elsewhere_keep_the_former_name(): void
    {
        // OptiNexus holds these; renaming them is a migration of OptiNexus data, not a code change (RENAME_OPTIENTRY.md).
        $this->assertSame('optiaccounting.', OutboxPublisher::EVENT_PREFIX);
        $this->assertSame('optiaccounting', $this->loadConfig()['optinexus']['application_code']);
    }
}
