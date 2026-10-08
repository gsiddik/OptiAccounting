<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Runs real parallel requests against the application. Each job is a separate PHP process that boots the app, waits for a
 * common start instant and then sends one request through the HTTP kernel, so the database sees genuinely concurrent
 * transactions (row locks, advisory locks, unique indexes) instead of a simulation inside one connection.
 */
final class RaceRunner
{
    /** @var list<array{process:resource,pipes:array<int,resource>,out:string}> */
    private array $workers = [];

    /**
     * @param  list<array<string,mixed>>  $jobs  ['method','uri','token','body'] or ['mode'=>'event','tenant_id','event'=>[...]]
     * @param  float  $delay  seconds until the common start instant (process boot takes a few hundred ms)
     */
    public function start(array $jobs, float $delay = 2.5): self
    {
        $startAt = microtime(true) + $delay;
        $env = $this->environment();
        foreach ($jobs as $job) {
            $payload = base64_encode(json_encode($job + ['start_at' => $startAt], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __DIR__.'/race_worker.php', $payload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a race worker.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $this->workers[] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        return $this;
    }

    public function running(int $index = 0): bool
    {
        $this->drain();

        return (bool) proc_get_status($this->workers[$index]['process'])['running'];
    }

    /** What the workers have printed so far (stdout and stderr), for failure messages. */
    public function output(): string
    {
        $this->drain();

        return json_encode(array_map(fn ($w) => ['stdout' => $w['out'], 'stderr' => substr($w['err'], 0, 600), 'running' => proc_get_status($w['process'])['running']], $this->workers));
    }

    /** @return list<array{status:int,body:array<string,mixed>|null,waited_ms:int}> results in start order */
    public function results(float $timeout = 60.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $this->drain();
            if (collect($this->workers)->every(fn ($w) => ! proc_get_status($w['process'])['running'])) {
                break;
            }
            usleep(20_000);
        }

        $this->drain();
        $results = [];
        foreach ($this->workers as $i => $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
                throw new RuntimeException("Race worker {$i} did not finish within {$timeout}s (a lock that is never released?).");
            }
            $decoded = json_decode($worker['out'], true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Race worker {$i} produced no result. stdout: {$worker['out']} stderr: ".substr($worker['err'], 0, 800));
            }
            $results[] = $decoded;
        }
        foreach ($this->workers as $worker) {
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
        $this->workers = [];

        return $results;
    }

    public function __destruct()
    {
        foreach ($this->workers as $worker) {
            if (is_resource($worker['process'])) {
                @proc_terminate($worker['process']);
            }
        }
    }

    private function drain(): void
    {
        foreach ($this->workers as $i => $worker) {
            $this->workers[$i]['out'] .= (string) stream_get_contents($worker['pipes'][1]);
            $this->workers[$i]['err'] .= (string) stream_get_contents($worker['pipes'][2]);
        }
    }

    /** The child must talk to the same (test) database as this process. */
    private function environment(): array
    {
        $db = config('database.connections.'.config('database.default'));

        return array_filter(array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4', 'APP_MAINTENANCE_DRIVER' => 'file',
        ]), fn ($v) => $v !== false && $v !== null);
    }
}
