<?php

/**
 * Child process of the concurrency tests: boots the real application, waits for a shared start instant, then runs one
 * request (one event posting, or one inbox receive) and prints a JSON result. Not a test itself (no Test suffix, never autoloaded).
 *
 * usage: php race_worker.php <base64(json job)>
 */

use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\EventInbox;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$job = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

while (microtime(true) < ($job['start_at'] ?? 0)) {
    usleep(200);
}
$began = microtime(true);

try {
    if (($job['mode'] ?? 'http') === 'event') {
        $app->instance('request', Request::create('/')); // what a console job has; the audit trail reads it
        $app->make(TenantContext::class)->setTenant($job['tenant_id']);
        $event = $app->make(PostingEngine::class)->postEvent(...$job['event']);
        $result = ['status' => 200, 'body' => ['event_id' => $event->id, 'event_status' => $event->status, 'journal_entry_id' => $event->journal_entry_id]];
    } elseif (($job['mode'] ?? 'http') === 'inbox') {
        $app->instance('request', Request::create('/'));
        $app->make(TenantContext::class)->setTenant($job['tenant_id']);
        $received = $app->make(EventInbox::class)->receive(IntegrationConnection::query()->findOrFail($job['connection_id']), $job['envelope']);
        $result = ['status' => 200, 'body' => ['outcome' => $received->outcome, 'id' => $received->event->id]];
    } else {
        $body = $job['body'] !== null ? json_encode($job['body']) : null;
        $request = Request::create($job['uri'], $job['method'], [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
        ], $body);
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }
} catch (DomainException $e) {
    $result = ['status' => $e->status, 'body' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
} catch (Throwable $e) {
    $result = ['status' => 500, 'body' => ['exception' => $e::class, 'message' => $e->getMessage()]];
}

echo json_encode($result + ['waited_ms' => (int) round((microtime(true) - $began) * 1000)]);
