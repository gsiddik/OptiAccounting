<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The lock sequence of every action that decides the state of the ledger (hard close, reopen, year end), in the one order the Posting Engine
 * uses: the tenant ledger gate first (exclusive here; a posting holds it shared), then the period row FOR UPDATE (a posting holds it FOR SHARE).
 * Callers run inside their own transaction; both locks are released when it ends.
 *
 * Source documents validate their period (FOR SHARE) before they reach the engine and its ledger gate, so a plain "gate, then period" would
 * form a cycle with such a posting: it waits for the gate we hold while we wait for the period it holds. The period is therefore requested with
 * NOWAIT inside a savepoint; when it is busy the savepoint is rolled back, which releases the gate again, and the whole step is retried after a
 * short pause. The closing action never waits while it holds a lock another party needs, so it cannot deadlock with a posting, and a posting
 * that already holds the period simply finishes first.
 */
class ClosingLocks
{
    private const ATTEMPTS = 40;

    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(private readonly TenantContext $context) {}

    /** Exclusive ledger gate only (year end locks the fiscal year row next). Waits for postings in flight. */
    public function ledger(): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [PostingEngine::ledgerGate($this->tenantId())]);
    }

    /** Exclusive ledger gate, then the period row FOR UPDATE; returns the locked period. */
    public function ledgerAndPeriod(string $periodId): AccountingPeriod
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            DB::statement('SAVEPOINT closing_locks');
            try {
                $this->ledger();
                $period = AccountingPeriod::query()->where('id', $periodId)->lock('for update nowait')->firstOrFail();
                DB::statement('RELEASE SAVEPOINT closing_locks');

                return $period;
            } catch (QueryException $e) {
                DB::statement('ROLLBACK TO SAVEPOINT closing_locks');
                if (($e->errorInfo[0] ?? '') !== self::LOCK_NOT_AVAILABLE) {
                    throw $e;
                }
                usleep(random_int(40_000, 120_000));
            } catch (\Throwable $e) {
                DB::statement('ROLLBACK TO SAVEPOINT closing_locks');
                throw $e;
            }
        }

        throw new DomainException('The period is busy with postings in flight; try again in a moment.', 'PERIOD_BUSY', 409);
    }

    private function tenantId(): string
    {
        return $this->context->tenantId() ?? throw new \LogicException('Closing locks require a tenant context.');
    }
}
