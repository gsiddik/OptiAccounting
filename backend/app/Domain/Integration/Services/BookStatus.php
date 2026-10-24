<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\ExternalEvent;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where a processed event stands in the books NOW (ecosystem audit A27). "Processed" only says that OptiEntry finished with the event; the
 * document it prepared may still wait for an approver or for somebody allowed to post it, and an informational fact has no document at all.
 * Nothing but a POSTED document (and its posted journal) is in the ledger, so a draft or a submitted document is never counted as booked.
 * The state is read live from the document each time, never from the status copied when the event was processed.
 *
 *   IN_LEDGER          the document is POSTED (or the event's journal is) : it reached the general ledger
 *   AWAITING_APPROVAL  the document is SUBMITTED or APPROVED and not posted yet
 *   DRAFT              the document was prepared and nobody has submitted or posted it
 *   REVERSED           the document was posted and later reversed
 *   VOID               the document was rejected or cancelled before posting
 *   INFORMATIONAL      the fact is recorded and deliberately books nothing
 *   NO_EFFECT          processing finished and nothing was booked
 *   UNCLASSIFIED       processed before outcomes were recorded and no document is linked
 */
class BookStatus
{
    public const STATES = ['IN_LEDGER', 'AWAITING_APPROVAL', 'DRAFT', 'REVERSED', 'VOID', 'INFORMATIONAL', 'NO_EFFECT', 'UNCLASSIFIED'];

    /** document_type of a source link => table of the document (both carry `status` and `journal_entry_id`). */
    private const DOCUMENTS = ['ap_invoice' => 'ap_invoices', 'vendor_payment' => 'vendor_payments', 'ar_invoice' => 'ar_invoices', 'customer_receipt' => 'customer_receipts'];

    public function __construct(private readonly TenantContext $context) {}

    /** The bucket of a document status. */
    public static function stateOfDocument(?string $status): ?string
    {
        return match ($status) {
            'POSTED' => 'IN_LEDGER',
            'SUBMITTED', 'APPROVED' => 'AWAITING_APPROVAL',
            'DRAFT' => 'DRAFT',
            'REVERSED' => 'REVERSED',
            'REJECTED', 'CANCELLED' => 'VOID',
            default => null,
        };
    }

    /**
     * @param  Collection<int,ExternalEvent>  $events
     * @return array<string,array{state:?string,documents:list<array<string,mixed>>}> event row id => its state and the documents behind it (newest first); a row that is not POSTED has state null
     */
    public function forEvents(Collection $events): array
    {
        $posted = $events->where('status', ExternalEvent::POSTED);
        $result = $events->mapWithKeys(fn (ExternalEvent $e) => [$e->id => ['state' => null, 'documents' => []]])->all();
        if ($posted->isEmpty()) {
            return $result;
        }

        $tenant = (string) $this->context->tenantId();
        $links = DB::table('external_source_links')->where('tenant_id', $tenant)->whereNotNull('document_id')
            ->whereIn('source_event_id', $posted->pluck('event_id')->all())->orderByDesc('created_at')->orderByDesc('id')
            ->get(['source_system', 'source_event_id', 'source_type', 'source_id', 'document_type', 'document_id', 'journal_id']);
        $documents = $this->documents($tenant, $links);
        $journals = DB::table('journal_entries')->where('tenant_id', $tenant)
            ->whereIn('id', [...$posted->pluck('resulting_journal_id')->filter()->all(), ...$documents->pluck('journal_entry_id')->filter()->all()])->pluck('status', 'id');

        foreach ($posted as $event) {
            $rows = $links->filter(fn ($l) => $l->source_system === $event->source_system && $l->source_event_id === $event->event_id);
            $docs = $rows->map(function ($l) use ($documents, $journals) {
                $doc = $documents[$l->document_type.':'.$l->document_id] ?? null;

                return $doc === null ? null : [
                    'document_type' => $l->document_type, 'document_id' => $l->document_id, 'status' => $doc->status, 'state' => self::stateOfDocument($doc->status),
                    'journal_id' => $doc->journal_entry_id, 'journal_status' => $doc->journal_entry_id === null ? null : ($journals[$doc->journal_entry_id] ?? null),
                    'source_type' => $l->source_type, 'source_id' => $l->source_id,
                ];
            })->filter()->values()->all();

            $result[$event->id] = ['state' => $this->state($event, $docs, $journals), 'documents' => $docs];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $docs @param Collection<string,string> $journals journal id => status */
    private function state(ExternalEvent $event, array $docs, Collection $journals): string
    {
        $outcome = $event->accounting_outcome;
        if ($outcome === ProcessOutcome::INFORMATIONAL) {
            return 'INFORMATIONAL';
        }
        if ($docs !== []) {
            return $docs[0]['state'] ?? 'UNCLASSIFIED';
        }
        if ($event->resulting_journal_id !== null) {
            return ($journals[$event->resulting_journal_id] ?? null) === 'POSTED' ? 'IN_LEDGER' : 'UNCLASSIFIED';
        }

        return $outcome === ProcessOutcome::NO_EFFECT ? 'NO_EFFECT' : 'UNCLASSIFIED';
    }

    /** @return Collection<string,object> "type:id" => the document's status and journal */
    private function documents(string $tenant, Collection $links): Collection
    {
        $found = collect();
        foreach (self::DOCUMENTS as $type => $table) {
            $ids = $links->where('document_type', $type)->pluck('document_id')->unique()->all();
            if ($ids === []) {
                continue;
            }
            foreach (DB::table($table)->where('tenant_id', $tenant)->whereIn('id', $ids)->get(['id', 'status', 'journal_entry_id']) as $row) {
                $found["{$type}:{$row->id}"] = $row;
            }
        }

        return $found;
    }

    /**
     * Live counts of the POSTED events of one connection by state (each event counted once, by its newest linked document).
     *
     * @return array<string,int> every state, zero when there is none
     */
    public function counts(string $connectionId): array
    {
        $tenant = (string) $this->context->tenantId();
        $counts = array_fill_keys(self::STATES, 0);
        $rows = DB::select(<<<'SQL'
            SELECT x.accounting_outcome AS outcome, x.has_journal, x.journal_status, coalesce(inv.status, pay.status, ari.status, rcp.status) AS doc_status, count(*) AS n
            FROM (
                SELECT DISTINCT ON (e.id) e.id, e.accounting_outcome, e.resulting_journal_id IS NOT NULL AS has_journal, j.status AS journal_status, l.document_type, l.document_id
                FROM external_events e
                LEFT JOIN external_source_links l ON l.tenant_id = e.tenant_id AND l.source_system = e.source_system AND l.source_event_id = e.event_id AND l.document_id IS NOT NULL
                LEFT JOIN journal_entries j ON j.tenant_id = e.tenant_id AND j.id = e.resulting_journal_id
                WHERE e.tenant_id = ? AND e.connection_id = ? AND e.status = 'POSTED'
                ORDER BY e.id, l.created_at DESC NULLS LAST, l.id DESC
            ) x
            LEFT JOIN ap_invoices inv ON x.document_type = 'ap_invoice' AND inv.tenant_id = ? AND inv.id = x.document_id
            LEFT JOIN vendor_payments pay ON x.document_type = 'vendor_payment' AND pay.tenant_id = ? AND pay.id = x.document_id
            LEFT JOIN ar_invoices ari ON x.document_type = 'ar_invoice' AND ari.tenant_id = ? AND ari.id = x.document_id
            LEFT JOIN customer_receipts rcp ON x.document_type = 'customer_receipt' AND rcp.tenant_id = ? AND rcp.id = x.document_id
            GROUP BY 1, 2, 3, 4
        SQL, [$tenant, $connectionId, $tenant, $tenant, $tenant, $tenant]);

        foreach ($rows as $row) {
            $state = match (true) {
                $row->outcome === ProcessOutcome::INFORMATIONAL => 'INFORMATIONAL',
                self::stateOfDocument($row->doc_status) !== null => self::stateOfDocument($row->doc_status),
                $row->has_journal => $row->journal_status === 'POSTED' ? 'IN_LEDGER' : 'UNCLASSIFIED',
                $row->outcome === ProcessOutcome::NO_EFFECT => 'NO_EFFECT',
                default => 'UNCLASSIFIED',
            };
            $counts[$state] += (int) $row->n;
        }

        return $counts;
    }
}
