<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationGap;
use App\Domain\Shared\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Read side and the review decision of gaps (ecosystem audit A18). A gap is the time a connection was paused or disconnected; the events the
 * source produced meanwhile are held or refused when they arrive (EventInbox) according to the policy chosen when the connection was switched
 * on again. For the two policies that hold (REQUIRES_REVIEW, BACKFILL_GAP) a person then decides, for the whole gap at once:
 *
 *   PROCESS  every held event goes through the normal retry path in source order (approval, segregation of duties and closed periods still
 *            apply; an event that cannot be booked stays in NEEDS_REVIEW with its reason). Nothing is booked twice: an event is processed once.
 *   DISMISS  every held event is refused for good (REJECTED), nothing is booked.
 *
 * At most BATCH events per call; call again while `remaining` is above zero.
 */
class GapService
{
    public const BATCH = 100;

    private const HELD_CODES = ['GAP_REVIEW', 'GAP_BACKFILL_REVIEW'];

    public function __construct(private readonly RetryService $retries, private readonly AuditService $audit) {}

    public function query(?string $connectionId = null): Builder
    {
        return IntegrationGap::query()->when($connectionId, fn ($q, $v) => $q->where('connection_id', $v))->orderByDesc('opened_at')->orderByDesc('id');
    }

    public function find(string $id): IntegrationGap
    {
        return (Str::isUuid($id) ? IntegrationGap::query()->find($id) : null) ?? throw new DomainException('Integration gap not found.', 'GAP_NOT_FOUND', 404);
    }

    /** @return array<string,mixed> */
    public function present(IntegrationGap $gap): array
    {
        $events = ExternalEvent::query()->where('gap_id', $gap->id);
        $byStatus = (clone $events)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $gap->id, 'connection_id' => $gap->connection_id, 'status' => $gap->status, 'open_reason' => $gap->open_reason, 'policy' => $gap->policy,
            'sequence_at_open' => $gap->sequence_at_open, 'ceiling_sequence' => $gap->ceiling_sequence, 'new_cutover_date' => $gap->new_cutover_date?->toDateString(),
            'opened_at' => $gap->opened_at->toIso8601String(), 'closed_at' => $gap->closed_at?->toIso8601String(),
            'events' => [
                'total' => (int) $byStatus->sum(), 'held' => $this->held($gap)->count(), 'processed' => (int) ($byStatus['POSTED'] ?? 0),
                'refused' => (int) ($byStatus['REJECTED'] ?? 0),
            ],
        ];
    }

    /** @return array{decision:string,handled:int,processed:int,remaining:int} */
    public function resolve(string $id, string $decision, string $reason): array
    {
        if (! in_array($decision, ['PROCESS', 'DISMISS'], true)) {
            throw new DomainException('The decision must be PROCESS or DISMISS.', 'GAP_DECISION_INVALID', 422, ['field' => 'decision']);
        }
        $gap = $this->find($id);
        if ($gap->status !== IntegrationGap::CLOSED) {
            throw new DomainException('The connection has not been switched on again yet; the gap is still open.', 'GAP_NOT_CLOSED', 409);
        }

        $handled = 0;
        $processed = 0;
        foreach ($this->held($gap)->orderBy('sequence')->orderBy('id')->limit(self::BATCH)->get() as $event) {
            try {
                $result = $decision === 'PROCESS'
                    ? $this->retries->retry($event->id, "Gap {$gap->id}: {$reason}")
                    : $this->retries->dismiss($event->id, "Gap {$gap->id}: {$reason}");
            } catch (DomainException $e) {
                if ($e->errorCode === 'CONNECTION_NOT_ACTIVE') {
                    throw $e; // nothing more can be booked until the connection is active; what was handled so far stays handled
                }

                continue;
            }
            $handled++;
            $processed += $result->status === ExternalEvent::POSTED ? 1 : 0;
        }
        $remaining = $this->held($gap)->count();
        $this->audit->record('accounting.integration.gap.resolved', 'integration_gap', $gap->id, null, [
            'decision' => $decision, 'reason' => $reason, 'handled' => $handled, 'processed' => $processed, 'remaining' => $remaining,
        ]);

        return ['decision' => $decision, 'handled' => $handled, 'processed' => $processed, 'remaining' => $remaining];
    }

    private function held(IntegrationGap $gap): Builder
    {
        return ExternalEvent::query()->where('gap_id', $gap->id)->where('status', ExternalEvent::NEEDS_REVIEW)->whereIn('last_error_code', self::HELD_CODES);
    }
}
