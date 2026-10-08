<?php

namespace Tests\Support;

use PDO;

/** Small helpers shared by tests that drive RaceRunner workers. */
trait RaceHelpers
{
    protected function job(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        return ['method' => $method, 'uri' => $uri, 'body' => $body, 'token' => $token ?? $this->token];
    }

    protected function assertNoServerErrors(array $results): void
    {
        foreach ($results as $i => $r) {
            $this->assertLessThan(500, $r['status'], "worker {$i} failed with a server error (deadlock or crash?): ".json_encode($r['body']));
        }
    }

    /** @return array<string,int> "status:code" => count, sorted */
    protected function outcomes(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $key = $r['status'].':'.($r['body']['code'] ?? '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /** Block until $count database sessions are waiting on a lock (instead of sleeping and hoping the worker got there). */
    protected function waitForLockWaiters(PDO $watcher, int $count, float $timeout = 20.0, ?RaceRunner $diagnose = null): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $watcher->query('select pg_stat_clear_snapshot()');
            $waiting = (int) $watcher->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->fetchColumn();
            if ($waiting >= $count) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $detail = $diagnose !== null ? ' Workers: '.$diagnose->output() : '';
        $this->fail("Expected {$count} session(s) waiting on a lock, saw {$waiting}.{$detail}");
    }
}
