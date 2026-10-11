<?php

namespace App\Domain\Integration\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalSourceLink;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Read side of the inbox: filtered listing, lookup (404 for another tenant's event) and the API form. Writing is EventInbox, EventProcessor and RetryService only. */
class EventReader
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param array{status?:?string,event_type?:?string,connection_id?:?string,conflict?:?bool,from?:?string,to?:?string} $filter from/to: occurred_at date range, tenant local dates, inclusive */
    public function query(array $filter = []): Builder
    {
        $zone = Tenant::query()->whereKey($this->context->tenantId())->value('timezone') ?? 'UTC';

        return ExternalEvent::query()
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->when($filter['connection_id'] ?? null, fn ($q, $v) => $q->where('connection_id', $v))
            ->when(array_key_exists('conflict', $filter) && $filter['conflict'] !== null, fn ($q) => $q->where('conflict', (bool) $filter['conflict']))
            ->when($filter['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', Carbon::parse($v, $zone)->startOfDay()->utc()))
            ->when($filter['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', Carbon::parse($v, $zone)->addDay()->startOfDay()->utc()))
            ->orderByDesc('received_at')->orderByDesc('id');
    }

    public function find(string $id): ExternalEvent
    {
        $event = Str::isUuid($id) ? ExternalEvent::query()->find($id) : null;

        return $event ?? throw new DomainException('Integration event not found.', 'EVENT_NOT_FOUND', 404);
    }

    /** The detail form adds the stored envelope and the links this event produced. */
    public function present(ExternalEvent $event, bool $detail = false): array
    {
        $row = [
            'id' => $event->id, 'connection_id' => $event->connection_id, 'source_system' => $event->source_system, 'event_id' => $event->event_id,
            'event_type' => $event->event_type, 'schema_version' => $event->schema_version, 'occurred_at' => $event->occurred_at->toIso8601String(),
            'sequence' => $event->sequence, 'status' => $event->status, 'attempts' => $event->attempts,
            'next_attempt_at' => $event->next_attempt_at?->toIso8601String(), 'last_error_code' => $event->last_error_code, 'last_error' => $event->last_error,
            'conflict' => $event->conflict, 'duplicate_count' => $event->duplicate_count, 'resulting_journal_id' => $event->resulting_journal_id,
            'received_at' => $event->received_at->toIso8601String(), 'processed_at' => $event->processed_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $row;
        }

        return $row + [
            'envelope' => $event->envelope,
            'source_links' => ExternalSourceLink::query()->where('source_system', $event->source_system)->where('source_event_id', $event->event_id)->orderBy('source_type')->orderBy('source_id')->get()
                ->map(fn (ExternalSourceLink $link) => $this->presentLink($link))->all(),
        ];
    }

    public function presentLink(ExternalSourceLink $link): array
    {
        return [
            'id' => $link->id, 'journal_id' => $link->journal_id, 'document_type' => $link->document_type, 'document_id' => $link->document_id,
            'source_system' => $link->source_system, 'source_type' => $link->source_type, 'source_id' => $link->source_id,
            'source_document_number' => $link->source_document_number, 'source_event_id' => $link->source_event_id, 'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
