<?php

namespace App\Domain\ProductCatalog\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Data-driven module dependency graph: direct and transitive lookups, cycle
 * prevention on write, and safe removal. Dependencies are never hardcoded.
 */
class ModuleDependencyService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return list<string> ids of every module required (directly or transitively) by $moduleId */
    public function requiredClosure(string $moduleId): array
    {
        $seen = [];
        $queue = [$moduleId];

        while ($queue) {
            $next = DB::table('module_dependencies')->whereIn('module_id', $queue)->pluck('requires_module_id')->all();
            $queue = array_values(array_diff($next, $seen, [$moduleId]));
            $seen = array_values(array_unique([...$seen, ...$queue]));
        }

        return $seen;
    }

    /** @return list<string> ids of modules that directly require $moduleId */
    public function directDependents(string $moduleId): array
    {
        return DB::table('module_dependencies')->where('requires_module_id', $moduleId)->pluck('module_id')->all();
    }

    /** @param list<string> $moduleIds @return list<string> same ids ordered so that requirements come first */
    public function orderByDependencies(array $moduleIds): array
    {
        usort($moduleIds, fn ($a, $b) => count($this->requiredClosure($a)) <=> count($this->requiredClosure($b)));

        return $moduleIds;
    }

    public function add(Module $module, Module $requires): void
    {
        DB::transaction(function () use ($module, $requires) {
            // Serialize graph writes so two concurrent inserts cannot jointly create a cycle.
            DB::select("SELECT pg_advisory_xact_lock(hashtext('module_dependencies'))");

            if ($module->id === $requires->id) {
                throw new DomainException('A module cannot depend on itself.', 'SELF_DEPENDENCY');
            }
            if (in_array($module->id, $this->requiredClosure($requires->id), true)) {
                throw new DomainException("{$requires->code} already requires {$module->code}: this would create a cycle.", 'CIRCULAR_DEPENDENCY');
            }
            if ($module->requires()->whereKey($requires->id)->exists()) {
                throw new DomainException('This dependency already exists.', 'DEPENDENCY_EXISTS', 409);
            }

            // Tenants already using $module must also hold $requires, otherwise their state would turn inconsistent.
            $orphans = DB::table('tenant_module_entitlements as e')
                ->where('e.module_id', $module->id)->whereIn('e.state', ['ACTIVE', 'READ_ONLY'])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_module_entitlements as r')
                    ->whereColumn('r.tenant_id', 'e.tenant_id')->where('r.module_id', $requires->id)
                    ->whereIn('r.state', ['ACTIVE', 'READ_ONLY']))
                ->distinct()->count('e.tenant_id');
            if ($orphans > 0) {
                throw new DomainException("{$orphans} tenant(s) use {$module->code} without {$requires->code}.", 'DEPENDENCY_CONFLICT', 409);
            }

            $module->requires()->attach($requires->id);
            $this->audit->record('module.dependency_added', 'module', $module->id, null, ['requires' => $requires->code]);
        });
    }

    public function remove(Module $module, Module $requires): void
    {
        DB::transaction(function () use ($module, $requires) {
            if (! $module->requires()->whereKey($requires->id)->exists()) {
                throw new DomainException('This dependency does not exist.', 'DEPENDENCY_NOT_FOUND', 404);
            }
            $module->requires()->detach($requires->id);
            $this->audit->record('module.dependency_removed', 'module', $module->id, ['requires' => $requires->code], null);
        });
    }
}
