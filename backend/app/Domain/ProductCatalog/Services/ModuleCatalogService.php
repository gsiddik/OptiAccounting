<?php

namespace App\Domain\ProductCatalog\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

class ModuleCatalogService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createModule(array $data): Module
    {
        return DB::transaction(function () use ($data) {
            $module = new Module($data);
            $module->status = Module::ACTIVE;
            $module->save();
            $this->audit->record('module.created', 'module', $module->id, null, $module->only(['code', 'name']));

            return $module;
        });
    }

    public function updateModule(Module $module, array $data): Module
    {
        return DB::transaction(function () use ($module, $data) {
            $before = $module->only(array_keys($data));
            $module->fill($data)->save();
            $this->audit->record('module.updated', 'module', $module->id, $before, $module->only(array_keys($data)));

            return $module;
        });
    }

    public function setModuleStatus(Module $module, string $status): Module
    {
        return DB::transaction(function () use ($module, $status) {
            if ($status === Module::INACTIVE) {
                $inUse = DB::table('tenant_module_entitlements')->where('module_id', $module->id)
                    ->whereIn('state', ['ACTIVE', 'READ_ONLY'])
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()->toDateString()))
                    ->distinct()->count('tenant_id');
                if ($inUse > 0) {
                    throw new DomainException("{$inUse} tenant(s) still use this module.", 'MODULE_IN_USE', 409);
                }
            }

            $before = ['status' => $module->status];
            $module->status = $status;
            $module->save();
            $this->audit->record('module.status_changed', 'module', $module->id, $before, ['status' => $status]);

            return $module;
        });
    }

    public function createFeature(Module $module, array $data): Feature
    {
        return DB::transaction(function () use ($module, $data) {
            $feature = new Feature($data);
            $feature->module_id = $module->id;
            $feature->status = 'ACTIVE';
            $feature->save();
            $this->audit->record('feature.created', 'feature', $feature->id, null, ['code' => $feature->code, 'module' => $module->code]);

            return $feature;
        });
    }

    public function updateFeature(Feature $feature, array $data): Feature
    {
        return DB::transaction(function () use ($feature, $data) {
            $before = $feature->only(array_keys($data));
            if (isset($data['status'])) {
                $feature->status = $data['status'];
                unset($data['status']);
            }
            $feature->fill($data)->save();
            $this->audit->record('feature.updated', 'feature', $feature->id, $before, $feature->only(array_keys($before)));

            return $feature;
        });
    }
}
