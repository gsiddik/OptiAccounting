<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls the source's cursor feed for one ACTIVE connection (the owner's transport decision, §7.1).
 *
 *   - Never inside a database transaction: the HTTP call comes first; each page then gets ONE transaction in which every event is ingested
 *     and the connection's cursor advances, so the cursor moves if and only if the page's events are stored.
 *   - A failed call, an invalid page or an unidentifiable envelope leaves the cursor unchanged, records the reason and counts a failure;
 *     five in a row turn the connection ERROR (ConnectionService::pullFailed). A good page resets the counter.
 *   - A pause, disconnect, reconfiguration or reconnection that happens while the HTTP call is running (ecosystem audit A20) stops the pull
 *     at the page boundary: inside the page transaction the connection row is locked FOR UPDATE and compared with the status and
 *     `lifecycle_version` the run started with; when they differ the page is not stored, the cursor stays and the summary says `halted`.
 *     Pausing therefore returns a clear boundary: nothing is stored or processed for the connection after it.
 *   - Events stored by a page are then processed one by one through EventProcessor (each isolated: one failing event never stops the others
 *     or the cursor).
 *   - A per-connection PostgreSQL advisory lock keeps two pulls (scheduler and "sync now") from running at once; the second one is skipped.
 *   - A run reads at most MAX_PAGES pages; the next scheduled pull continues from the cursor.
 */
class PullService
{
    public const MAX_PAGES = 20;

    private const LOCK_NAMESPACE = 'integration.pull';

    public function __construct(
        private readonly FeedClient $feed,
        private readonly EventInbox $inbox,
        private readonly EventProcessor $processor,
        private readonly ConnectionService $connections,
        private readonly AuditService $audit,
    ) {}

    /** "Sync now" (permission accounting.integration.manage): one pull on demand, audited; 409 while another pull of the connection is running. */
    public function syncNow(string $id): array
    {
        $summary = $this->pull($this->connections->find($id));
        if ($summary['skipped']) {
            throw new DomainException('Another pull of this connection is running.', 'PULL_IN_PROGRESS', 409);
        }
        $this->audit->record('accounting.integration.connection.sync_now', 'integration_connection', $id, null, array_diff_key($summary, ['skipped' => true]));

        return $summary;
    }

    /** @return array{skipped:bool,halted:bool,pages:int,received:int,duplicates:int,conflicts:int,processed:int,posted:int,journals:int,error:?string} `posted` counts events processed to the end, `journals` those that reached the books as a posted journal (see ProcessOutcome) */
    public function pull(Connection $connection): array
    {
        $summary = ['skipped' => false, 'halted' => false, 'pages' => 0, 'received' => 0, 'duplicates' => 0, 'conflicts' => 0, 'processed' => 0, 'posted' => 0, 'journals' => 0, 'error' => null];

        $locked = DB::selectOne('select pg_try_advisory_lock(hashtext(?), hashtext(?)) as locked', [self::LOCK_NAMESPACE, $connection->id])->locked;
        if (! in_array($locked, [true, 't', 'true', 1, '1'], true)) {
            return ['skipped' => true] + $summary;
        }

        try {
            return $this->run($connection->id, $summary);
        } finally {
            DB::selectOne('select pg_advisory_unlock(hashtext(?), hashtext(?))', [self::LOCK_NAMESPACE, $connection->id]);
        }
    }

    private function run(string $id, array $summary): array
    {
        $connection = $this->connections->find($id);
        if ($connection->status !== Connection::ACTIVE) {
            throw new DomainException('Only an ACTIVE connection can be pulled.', 'CONNECTION_NOT_ACTIVE', 409, ['status' => $connection->status]);
        }
        $version = $connection->lifecycle_version;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $cursor = $connection->last_cursor;
            try {
                $feed = $this->feed->fetch($connection, $cursor);
                $stored = DB::transaction(fn () => $this->store($id, $version, $cursor, $feed));
            } catch (Throwable $e) {
                $expected = $e instanceof FeedException || $e instanceof DomainException;
                if (! $expected) {
                    report($e);
                }
                $summary['error'] = $expected ? $e->getMessage() : 'The page could not be stored.';
                $this->connections->pullFailed($id, $summary['error'], $version);

                return $summary;
            }
            if ($stored === null) {
                return ['halted' => true] + $summary; // the connection was paused, disconnected or reconfigured while the page was on its way
            }

            $summary['pages']++;
            $summary['received'] += count($stored['created']);
            $summary['duplicates'] += $stored['duplicates'];
            $summary['conflicts'] += $stored['conflicts'];
            foreach ($stored['created'] as $event) {
                if ($event->status !== ExternalEvent::RECEIVED) {
                    continue; // already decided at the gate (REJECTED / NEEDS_REVIEW)
                }
                $summary['processed']++;
                try {
                    $done = $this->processor->process($event);
                    $summary['posted'] += $done->status === ExternalEvent::POSTED ? 1 : 0;
                    $summary['journals'] += $done->accounting_outcome === ProcessOutcome::JOURNAL_POSTED ? 1 : 0;
                } catch (Throwable $e) {
                    report($e); // an infrastructure fault on one event: it stays RECEIVED and the scheduler picks it up
                }
            }

            $connection = $this->connections->find($id);
            if ($connection->status !== Connection::ACTIVE || $connection->lifecycle_version !== $version) {
                return ['halted' => true] + $summary;
            }
            if (! $feed['has_more'] || $connection->last_cursor === $cursor) {
                break;
            }
        }

        return $summary;
    }

    /**
     * One page, in the caller's transaction. The connection is locked FOR UPDATE first, so a pause or disconnect that is committing waits for
     * this page and one that committed earlier is seen here; null means the connection is no longer what the pull started with.
     *
     * @return array{created:list<ExternalEvent>,duplicates:int,conflicts:int}|null
     */
    private function store(string $id, int $version, ?string $cursor, array $feed): ?array
    {
        $connection = $this->connections->find($id, lock: true);
        if ($connection->status !== Connection::ACTIVE || $connection->lifecycle_version !== $version || $connection->last_cursor !== $cursor) {
            return null;
        }

        $stored = ['created' => [], 'duplicates' => 0, 'conflicts' => 0];
        foreach ($feed['data'] as $envelope) {
            $result = $this->inbox->receive($connection, $envelope);
            match (true) {
                $result->created() => $stored['created'][] = $result->event,
                $result->duplicate() => $stored['duplicates']++,
                default => $stored['conflicts']++,
            };
        }
        $this->connections->pullSucceeded($connection, $feed['next_cursor']);

        return $stored;
    }
}
