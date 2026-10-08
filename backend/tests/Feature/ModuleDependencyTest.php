<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Module dependency graph (OA0 §37): valid, missing, transitive, reverse, self, circular, deactivation, bundle closure. */
class ModuleDependencyTest extends TestCase
{
    use Fixtures;

    private function module(string $code): string
    {
        return $this->asPlatform()->postJson('/api/v1/platform/modules', ['code' => $code, 'name' => $code])->assertCreated()->json('id');
    }

    private function dependsOn(string $module, string $requiresCode)
    {
        return $this->asPlatform()->postJson("/api/v1/platform/modules/{$module}/dependencies", ['requires' => $requiresCode]);
    }

    private function grant(Tenant $tenant, string $code, string $state = 'ACTIVE')
    {
        return $this->asPlatform()->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements/modules", [
            'module_code' => $code, 'state' => $state, 'source' => 'MANUAL_OVERRIDE',
        ]);
    }

    /** @return array{0:string,1:string,2:string} ids of T_A <- T_B <- T_C (C requires B requires A) */
    private function chain(): array
    {
        [$a, $b, $c] = [$this->module('T_A'), $this->module('T_B'), $this->module('T_C')];
        $this->dependsOn($b, 'T_A')->assertSuccessful();
        $this->dependsOn($c, 'T_B')->assertSuccessful();

        return [$a, $b, $c];
    }

    public function test_the_seeded_catalog_has_the_documented_dependencies(): void
    {
        $service = app(ModuleDependencyService::class);
        $id = fn (string $code) => Module::query()->where('code', $code)->value('id');

        $this->assertSame([], $service->requiredClosure($id('ACCOUNTING_CORE')));
        $this->assertSame([$id('ACCOUNTING_CORE')], $service->requiredClosure($id('ACCOUNTING_AP')));
        $this->assertEqualsCanonicalizing(
            [$id('ACCOUNTING_CORE'), $id('ACCOUNTING_REPORTING')],
            $service->requiredClosure($id('ACCOUNTING_ANALYTICS')),
        );
    }

    public function test_a_valid_dependency_is_recorded_and_transitive_requirements_are_resolved(): void
    {
        [$a, $b, $c] = $this->chain();
        $service = app(ModuleDependencyService::class);

        $this->assertEqualsCanonicalizing([$b, $a], $service->requiredClosure($c));
        $this->assertSame([$c], $service->directDependents($b)); // reverse lookup
        $this->assertSame([$a, $b, $c], $service->orderByDependencies([$c, $a, $b]));
    }

    public function test_a_module_cannot_require_itself(): void
    {
        $a = $this->module('T_A');

        $this->dependsOn($a, 'T_A')->assertStatus(422)->assertJsonPath('code', 'SELF_DEPENDENCY');
    }

    public function test_direct_and_transitive_cycles_are_refused(): void
    {
        [$a, $b] = $this->chain();

        $this->dependsOn($b, 'T_B')->assertStatus(422)->assertJsonPath('code', 'SELF_DEPENDENCY');
        $this->dependsOn($a, 'T_B')->assertStatus(422)->assertJsonPath('code', 'CIRCULAR_DEPENDENCY');   // A -> B -> A
        $this->dependsOn($a, 'T_C')->assertStatus(422)->assertJsonPath('code', 'CIRCULAR_DEPENDENCY');   // A -> C -> B -> A
        $this->dependsOn($b, 'T_A')->assertStatus(409)->assertJsonPath('code', 'DEPENDENCY_EXISTS');
    }

    public function test_a_dependency_can_be_removed_and_a_missing_one_is_not_found(): void
    {
        [$a, $b] = $this->chain();

        $this->asPlatform()->deleteJson("/api/v1/platform/modules/{$b}/dependencies/{$a}")->assertOk();
        $this->assertSame([], app(ModuleDependencyService::class)->requiredClosure($b));
        $this->asPlatform()->deleteJson("/api/v1/platform/modules/{$b}/dependencies/{$a}")->assertNotFound();
    }

    public function test_a_module_is_only_granted_when_its_requirements_are_met(): void
    {
        $this->chain();
        $tenant = $this->tenant('alpha', subscribed: false);

        $this->grant($tenant, 'T_C')->assertStatus(409)->assertJsonPath('code', 'DEPENDENCY_MISSING')->assertJsonPath('details.missing.0', 'T_A');
        $this->grant($tenant, 'T_A')->assertCreated();
        $this->grant($tenant, 'T_C')->assertStatus(409)->assertJsonPath('details.missing.0', 'T_B'); // transitive requirement still open
        $this->grant($tenant, 'T_B')->assertCreated();
        $this->grant($tenant, 'T_C')->assertCreated();
    }

    public function test_an_active_module_cannot_depend_on_a_module_that_is_only_read_only(): void
    {
        $this->chain();
        $tenant = $this->tenant('alpha', subscribed: false);

        $this->grant($tenant, 'T_A', 'READ_ONLY')->assertCreated();
        $this->grant($tenant, 'T_B', 'ACTIVE')->assertStatus(409)->assertJsonPath('code', 'DEPENDENCY_MISSING');
        $this->grant($tenant, 'T_B', 'READ_ONLY')->assertCreated();
    }

    public function test_a_required_module_cannot_be_weakened_while_a_dependent_is_active(): void
    {
        $this->chain();
        $tenant = $this->tenant('alpha', subscribed: false);
        $a = $this->grant($tenant, 'T_A')->json('id');
        $b = $this->grant($tenant, 'T_B')->json('id');

        $patch = fn (string $id, array $body) => $this->asPlatform()->patchJson("/api/v1/platform/tenants/{$tenant->id}/entitlements/modules/{$id}", $body);

        $patch($a, ['state' => 'DISABLED'])->assertStatus(409)->assertJsonPath('code', 'ACTIVE_DEPENDENTS')->assertJsonPath('details.dependents.0', 'T_B');
        $patch($a, ['state' => 'READ_ONLY'])->assertStatus(409)->assertJsonPath('code', 'ACTIVE_DEPENDENTS');

        $patch($b, ['state' => 'DISABLED'])->assertOk();
        $patch($a, ['state' => 'DISABLED'])->assertOk();
    }

    public function test_a_module_in_use_cannot_be_deactivated_but_an_unused_one_can(): void
    {
        $a = $this->module('T_A');
        $unused = $this->module('T_UNUSED');
        $tenant = $this->tenant('alpha', subscribed: false);
        $this->grant($tenant, 'T_A')->assertCreated();

        $this->asPlatform()->postJson("/api/v1/platform/modules/{$a}/status", ['status' => 'INACTIVE'])
            ->assertStatus(409)->assertJsonPath('code', 'MODULE_IN_USE');
        $this->asPlatform()->postJson("/api/v1/platform/modules/{$unused}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->grant($tenant, 'T_UNUSED')->assertStatus(422)->assertJsonPath('code', 'MODULE_INACTIVE');
    }

    public function test_a_new_dependency_is_refused_when_a_tenant_already_uses_the_module_without_it(): void
    {
        $a = $this->module('T_A');
        $b = $this->module('T_B');
        $tenant = $this->tenant('alpha', subscribed: false);
        $this->grant($tenant, 'T_B')->assertCreated();

        $this->dependsOn($b, 'T_A')->assertStatus(409)->assertJsonPath('code', 'DEPENDENCY_CONFLICT');
        $this->grant($tenant, 'T_A')->assertCreated();
        $this->dependsOn($b, 'T_A')->assertSuccessful();
        $this->assertNotNull($a);
    }

    public function test_a_bundle_must_contain_the_whole_dependency_closure(): void
    {
        $this->chain();
        $bundle = fn (array $modules) => $this->asPlatform()->postJson('/api/v1/platform/bundles', [
            'code' => 'B'.strtoupper(substr(uniqid(), -6)), 'name' => 'Bundle', 'modules' => $modules,
        ]);

        $bundle(['T_B'])->assertStatus(422)->assertJsonPath('code', 'BUNDLE_DEPENDENCY_MISSING');
        $bundle(['T_C', 'T_A'])->assertStatus(422)->assertJsonPath('code', 'BUNDLE_DEPENDENCY_MISSING'); // transitive B missing
        $bundle(['T_C', 'T_B', 'T_A'])->assertCreated();
        $bundle(['NO_SUCH_MODULE'])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_MODULE');
    }

    public function test_removing_a_module_from_a_bundle_keeps_the_closure_rule(): void
    {
        $this->chain();
        $code = 'KEEP';
        $this->asPlatform()->postJson('/api/v1/platform/bundles', ['code' => $code, 'name' => 'Keep', 'modules' => ['T_A', 'T_B']])->assertCreated();
        $id = $this->asPlatform()->getJson('/api/v1/platform/bundles')->json('data.0.id');

        $this->asPlatform()->patchJson("/api/v1/platform/bundles/{$id}", ['modules' => ['T_B']])
            ->assertStatus(422)->assertJsonPath('code', 'BUNDLE_DEPENDENCY_MISSING');
    }

    public function test_a_bundle_subscription_provisions_modules_in_dependency_order(): void
    {
        $this->chain();
        $this->asPlatform()->postJson('/api/v1/platform/bundles', ['code' => 'CHAIN', 'name' => 'Chain', 'modules' => ['T_C', 'T_B', 'T_A']])->assertCreated();
        $tenant = $this->tenant('alpha', subscribed: false);

        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions", [
            'bundle_code' => 'CHAIN', 'starts_on' => $tenant->businessDate(), 'status' => 'ACTIVE',
        ])->assertCreated();

        $this->assertSame(3, $this->rows('tenant_module_entitlements', ['tenant_id' => $tenant->id, 'source' => 'BUNDLE', 'state' => 'ACTIVE']));
    }
}
