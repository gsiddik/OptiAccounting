<?php

namespace Tests\Support;

/** Starts tests/Support/fake-nexus/router.php on a free local port, so a contract test crosses a real socket (no Http::fake). */
final class FakeNexusServer
{
    /** @var resource|null */
    private $process = null;

    public readonly string $dir;

    public readonly int $port;

    private function __construct(string $dir, int $port)
    {
        $this->dir = $dir;
        $this->port = $port;
    }

    /** @return self|null null when the environment cannot run a local server (the caller skips) */
    public static function start(): ?self
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (! $probe) {
            return null;
        }
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $dir = sys_get_temp_dir().'/fake-nexus-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $server = new self($dir, $port);

        $router = __DIR__.'/fake-nexus/router.php';
        $server->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_NEXUS_DIR' => $dir, 'PHP_CLI_SERVER_WORKERS' => '2'] + (getenv() ?: []),
        );

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket) {
                fclose($socket);

                return $server;
            }
            usleep(100_000);
        }
        $server->stop();

        return null;
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /** @param array<string,mixed> $state */
    public function state(array $state): void
    {
        file_put_contents($this->dir.'/state.json', json_encode($state));
    }

    /** @return list<array<string,mixed>> what actually arrived on the wire */
    public function requests(string $path): array
    {
        $file = $this->dir.'/requests.jsonl';
        if (! is_file($file)) {
            return [];
        }
        $rows = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($file))));

        return array_values(array_filter($rows, fn ($r) => $r['path'] === $path));
    }

    public function stop(): void
    {
        if ($this->process) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                // The built-in server runs in a child of the shell proc_open starts on some platforms: end the whole group.
                @exec('kill '.(int) $status['pid'].' 2>/dev/null');
            }
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }
}
