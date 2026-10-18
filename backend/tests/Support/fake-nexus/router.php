<?php

/**
 * A stand-alone OptiNexus for contract tests: the real HTTP boundary (a PHP built-in server on a local port), answering only what
 * GOVERNANCE_CONTRACTS.md documents, with the documented envelope. State and the request log live in $FAKE_NEXUS_DIR so a test can
 * change the behaviour between calls (state.json) and read what actually arrived on the wire (requests.jsonl).
 *
 *   state.json: {"deny": {"<user>|<tenant>": "user_suspended"}, "delay_ms": 0, "status": 200, "ttl": 300}
 */
$dir = getenv('FAKE_NEXUS_DIR') ?: sys_get_temp_dir();
$state = is_file("$dir/state.json") ? (json_decode((string) file_get_contents("$dir/state.json"), true) ?: []) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$raw = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$header = fn (string $name) => $headers[$name] ?? $headers[strtolower($name)] ?? null;

file_put_contents("$dir/requests.jsonl", json_encode(['method' => $method, 'path' => $path, 'authorization' => $header('Authorization'), 'correlation' => $header('X-Correlation-Id'), 'content_type' => $header('Content-Type'), 'body' => $raw]).PHP_EOL, FILE_APPEND);

function reply(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($body);
    exit;
}

if (! empty($state['delay_ms'])) {
    usleep((int) $state['delay_ms'] * 1000);
}
if (! empty($state['status']) && (int) $state['status'] !== 200 && $path !== '/api/v1/oauth/token') {
    reply((int) $state['status'], ['success' => false, 'error' => ['code' => 'UNAVAILABLE', 'message' => 'try later']], isset($state['retry_after']) ? ['Retry-After' => (string) $state['retry_after']] : []);
}

if ($path === '/api/v1/oauth/token' && $method === 'POST') {
    parse_str($raw, $form);
    if (($form['grant_type'] ?? '') !== 'client_credentials' || ($form['client_id'] ?? '') !== 'svc-client' || ($form['client_secret'] ?? '') !== 'svc-secret') {
        reply(401, ['message' => 'invalid_client']);
    }
    $scopes = explode(' ', (string) ($form['scope'] ?? ''));
    reply(200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'contract-token', 'scope' => implode(' ', array_intersect($scopes, ['authorization.check', 'commercial.read', 'event.write', 'audit.write', 'tenant.delegate']))]);
}

if (($header('Authorization') ?? '') !== 'Bearer contract-token') {
    reply(401, ['success' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated.']]);
}

if ($path === '/api/v1/authorization/product-context' && $method === 'POST') {
    $body = json_decode($raw, true);
    $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    if (! is_array($body) || ! preg_match($uuid, (string) ($body['user_id'] ?? '')) || ! preg_match($uuid, (string) ($body['tenant_id'] ?? ''))) {
        reply(422, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The given data was invalid.', 'details' => ['user_id' => ['The user id field must be a valid UUID.']]]]);
    }
    $reason = $state['deny']["{$body['user_id']}|{$body['tenant_id']}"] ?? null;

    // The shape of GOVERNANCE_CONTRACTS.md §3 / AuthorizationController::productContext, member for member.
    reply(200, ['success' => true, 'data' => [
        'schema_version' => '1.0',
        'user_id' => $body['user_id'],
        'tenant' => $reason === null ? ['id' => $body['tenant_id'], 'code' => 'ACME-ID', 'name' => 'PT Acme Indonesia'] : null,
        'application_code' => 'optiaccounting',
        'allowed' => $reason === null,
        'deny_reason' => $reason,
        'products' => $reason === null ? [
            ['code' => 'optiaccounting', 'name' => 'OptiEntry', 'launch_url' => 'https://entry.test/', 'current' => true],
            ['code' => 'optifleet', 'name' => 'OptiFleet', 'launch_url' => 'https://fleet.test/app', 'current' => false],
        ] : [],
        'evaluated_at' => gmdate('c'),
        'ttl_seconds' => $state['ttl'] ?? 300,
    ]]);
}

reply(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']]);
