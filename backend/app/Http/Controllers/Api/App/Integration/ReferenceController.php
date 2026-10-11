<?php

namespace App\Http\Controllers\Api\App\Integration;

use App\Domain\Integration\Models\ExternalDimension;
use App\Domain\Integration\Models\ExternalSourceLink;
use App\Domain\Integration\Services\EventReader;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the source system owns that we only reference (dimensions) and the trace from our journals / documents back to source documents (source links). */
class ReferenceController extends AppController
{
    public function __construct(TenantContext $context, private readonly EventReader $events)
    {
        parent::__construct($context);
    }

    public function dimensions(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'source_system' => ['nullable', 'string', 'max:40'], 'entity_type' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $like = isset($filter['q']) ? '%'.addcslashes($filter['q'], '\\%_').'%' : null;

        $dimensions = ExternalDimension::query()
            ->when($filter['source_system'] ?? null, fn ($q, $v) => $q->where('source_system', $v))
            ->when($filter['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($like, fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'ilike', $v)->orWhere('name', 'ilike', $v)))
            ->orderBy('entity_type')->orderBy('code')->orderBy('id')->paginate($filter['per_page'] ?? 50);

        return response()->json($dimensions->through(fn (ExternalDimension $d) => [
            'id' => $d->id, 'source_system' => $d->source_system, 'source_tenant_reference' => $d->source_tenant_reference, 'entity_type' => $d->entity_type,
            'external_id' => $d->external_id, 'code' => $d->code, 'name' => $d->name, 'status' => $d->status, 'metadata' => $d->metadata,
        ]));
    }

    /** The source documents behind one journal, or behind one document of ours (journal_id, or document_type with document_id). */
    public function sourceLinks(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'journal_id' => ['required_without:document_id', 'nullable', 'uuid'],
            'document_type' => ['required_with:document_id', 'nullable', 'string', 'max:40'],
            'document_id' => ['required_without:journal_id', 'nullable', 'uuid'],
        ]);

        $links = ExternalSourceLink::query()
            ->when($filter['journal_id'] ?? null, fn ($q, $v) => $q->where('journal_id', $v))
            ->when($filter['document_id'] ?? null, fn ($q, $v) => $q->where('document_id', $v)->where('document_type', $filter['document_type']))
            ->orderBy('source_system')->orderBy('source_type')->orderBy('source_id')->get();

        return response()->json(['data' => $links->map(fn (ExternalSourceLink $link) => $this->events->presentLink($link))->all()]);
    }
}
