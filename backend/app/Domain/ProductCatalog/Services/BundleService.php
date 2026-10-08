<?php

namespace App\Domain\ProductCatalog\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/** Data-driven bundles: composition lives in rows; a bundle must be closed under module dependencies. */
class BundleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ModuleDependencyService $dependencies,
    ) {}

    /**
     * @param  array{code:string,name:string,description?:?string}  $data
     * @param  list<string>  $moduleCodes
     * @param  array<string,?int>  $capacities  limit_code => value (null = unlimited)
     */
    public function create(array $data, array $moduleCodes, array $capacities = []): Bundle
    {
        return DB::transaction(function () use ($data, $moduleCodes, $capacities) {
            $bundle = new Bundle($data);
            $bundle->status = 'ACTIVE';
            $bundle->save();
            $this->replaceComposition($bundle, $moduleCodes, $capacities);
            $this->audit->record('bundle.created', 'bundle', $bundle->id, null, ['code' => $bundle->code, 'modules' => $moduleCodes, 'capacities' => $capacities]);

            return $bundle->load('modules', 'capacities');
        });
    }

    public function update(Bundle $bundle, array $data, ?array $moduleCodes, ?array $capacities): Bundle
    {
        return DB::transaction(function () use ($bundle, $data, $moduleCodes, $capacities) {
            $before = ['name' => $bundle->name, 'status' => $bundle->status, 'modules' => $bundle->modules()->pluck('code')->sort()->values()->all()];
            if (isset($data['status'])) {
                $bundle->status = $data['status'];
                unset($data['status']);
            }
            $bundle->fill($data)->save();
            if ($moduleCodes !== null || $capacities !== null) {
                $this->replaceComposition($bundle, $moduleCodes ?? $bundle->modules()->pluck('code')->all(), $capacities);
            }
            $this->audit->record('bundle.updated', 'bundle', $bundle->id, $before, [
                'name' => $bundle->name, 'status' => $bundle->status, 'modules' => $bundle->modules()->pluck('code')->sort()->values()->all(),
            ]);

            return $bundle->load('modules', 'capacities');
        });
    }

    private function replaceComposition(Bundle $bundle, array $moduleCodes, ?array $capacities): void
    {
        $modules = Module::query()->whereIn('code', $moduleCodes)->get();
        if ($modules->count() !== count(array_unique($moduleCodes))) {
            throw new DomainException('Unknown module code in bundle.', 'UNKNOWN_MODULE');
        }

        $ids = $modules->pluck('id')->all();
        foreach ($modules as $module) {
            $missing = array_diff($this->dependencies->requiredClosure($module->id), $ids);
            if ($missing) {
                $codes = Module::query()->whereIn('id', $missing)->pluck('code')->implode(', ');
                throw new DomainException("{$module->code} requires {$codes}, which the bundle does not include.", 'BUNDLE_DEPENDENCY_MISSING');
            }
        }

        $bundle->modules()->sync($ids);

        if ($capacities !== null) {
            foreach (array_keys($capacities) as $code) {
                if (! in_array($code, CapacityService::codes(), true)) {
                    throw new DomainException("Unknown capacity code {$code}.", 'UNKNOWN_CAPACITY');
                }
            }
            $bundle->capacities()->delete();
            foreach ($capacities as $code => $value) {
                $bundle->capacities()->create(['limit_code' => $code, 'limit_value' => $value]);
            }
        }
    }
}
