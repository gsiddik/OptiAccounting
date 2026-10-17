<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\PermissionCatalog;
use App\Domain\Integration\Optinexus\DelegableSet;
use App\Domain\Integration\Optinexus\OptinexusManifest;
use App\Domain\Integration\Optinexus\PermissionCapabilities;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/** Audit A02/R08: manifest v2 (version, revision, capability and delegable per permission) and the explicit delegable set. */
class ManifestV2Test extends OptinexusTestCase
{
    private function manifest(): array
    {
        return app(OptinexusManifest::class)->build();
    }

    public function test_the_manifest_carries_a_version_a_stable_revision_and_both_new_fields_on_every_permission(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(2, $manifest['manifest_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $manifest['revision']);
        $this->assertSame($manifest['revision'], $this->manifest()['revision'], 'same content, same revision');

        $codes = DB::table('modules')->pluck('code')->merge(DB::table('features')->pluck('code'))->all();
        foreach ($manifest['permissions'] as $permission) {
            $this->assertArrayHasKey('capability_code', $permission);
            $this->assertIsBool($permission['delegable']);
            if ($permission['capability_code'] !== null) {
                $this->assertContains($permission['capability_code'], $codes, "{$permission['permission_key']} names a module or feature of the capability list");
            }
        }
        // v1 fields are still there.
        $this->assertSame(['permission_key', 'name', 'description'], array_slice(array_keys($manifest['permissions'][0]), 0, 3));
    }

    public function test_the_revision_follows_the_catalog_and_not_the_deployment(): void
    {
        $revision = $this->manifest()['revision'];

        config(['optientry.optinexus.sso.frontend_url' => 'https://other.example', 'optientry.optinexus.sso.redirect_uri' => 'https://other.example/cb']);
        $this->assertSame($revision, $this->manifest()['revision'], 'URLs are not part of the catalog');

        DB::table('permissions')->where('code', 'audit.view')->update(['description' => 'Changed']);
        $this->assertNotSame($revision, $this->manifest()['revision']);
    }

    public function test_the_command_prints_the_same_manifest(): void
    {
        $buffer = new BufferedOutput;
        Artisan::call('optientry:nexus:manifest', [], $buffer);
        $printed = json_decode($buffer->fetch(), true);

        $this->assertSame(2, $printed['manifest_version']);
        $this->assertSame($this->manifest()['revision'], $printed['revision']);
        $this->assertContains('tenant.delegate', $printed['service_account']['scopes']);
    }

    public function test_every_listed_code_exists_and_nothing_that_governs_credentials_periods_or_connections_is_delegable(): void
    {
        $catalog = PermissionCatalog::codes('tenant');

        $this->assertSame([], array_values(array_diff(DelegableSet::codes(), $catalog)), 'a listed code that is not in the catalog');
        $this->assertSame(count(DelegableSet::codes()), count(array_unique(DelegableSet::codes())), 'no duplicates');

        $forbidden = [
            '/^access\./' => 'who may do what',
            '/^organization\.manage$/' => 'organisation structure',
            '/^accounting\.integration\./' => 'integration connections',
            '/^accounting\.period\.(manage|close|soft_close|hard_close|reopen|preclose)$/' => 'period state',
            '/^accounting\.closing\./' => 'closing policy and checklist',
            '/^accounting\.year_end\.run$/' => 'year-end closing',
            '/^accounting\.journal\.post_soft_closed$/' => 'posting into a soft-closed period',
            '/^accounting\.ap_invoice\.override_duplicate$/' => 'control override',
            '/^accounting\.opening_balance\.(manage|post)$/' => 'opening balance',
            '/^accounting\.(profile|coa|posting_rule|account_mapping|tax|currency|exchange_rate|report_definition)\.manage$/' => 'what the books are made of',
            '/^accounting\.(expense_category|asset_category|cash_bank)\.manage$/' => 'masters that steer postings and payments',
            '/^platform\./' => 'platform',
        ];
        foreach (DelegableSet::codes() as $code) {
            foreach ($forbidden as $pattern => $why) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$code} must not be delegable ({$why})");
            }
        }
    }

    public function test_anything_not_listed_is_not_delegable_including_a_new_permission(): void
    {
        $this->assertFalse(DelegableSet::allows('accounting.something.new'));
        $this->assertFalse(DelegableSet::allows(''));

        DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'code' => 'accounting.new_thing.view', 'scope' => 'tenant', 'group' => 'Journals', 'description' => 'New', 'created_at' => now(), 'updated_at' => now()]);
        $row = collect($this->manifest()['permissions'])->firstWhere('name', 'accounting.new_thing.view');
        $this->assertNotNull($row);
        $this->assertFalse($row['delegable']);
        $this->assertNull($row['capability_code']);
    }

    public function test_the_manifest_marks_exactly_the_listed_permissions_delegable(): void
    {
        $delegable = collect($this->manifest()['permissions'])->where('delegable', true)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(DelegableSet::codes(), $delegable);
        $this->assertGreaterThan(80, count($delegable), 'day-to-day accounting work is delegable');
        $this->assertLessThan(count(PermissionCatalog::codes('tenant')), count($delegable));
    }

    public function test_the_capability_of_a_permission_is_the_one_its_routes_demand(): void
    {
        $map = PermissionCapabilities::map();

        $this->assertSame('JOURNAL', $map['accounting.journal.post']);
        $this->assertSame('VENDOR_INVOICE', $map['accounting.ap_invoice.post']);
        $this->assertSame('INTEGRATION_CONNECTION', $map['accounting.integration.manage'] ?? null);
        $this->assertNull($map['access.user.manage'], 'access administration needs no module');
        $this->assertNull($map['organization.view']);

        $byPermission = collect($this->manifest()['permissions'])->pluck('capability_code', 'name');
        $this->assertSame('JOURNAL', $byPermission['accounting.journal.reverse']);
    }
}
