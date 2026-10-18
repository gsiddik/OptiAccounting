<?php

namespace App\Domain\Integration\Optinexus\Delegation;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Thin HTTP client of the OptiNexus tenant delegation API (docs/ecosystem/GOVERNANCE_CONTRACTS.md §2 and §2b invitations, scope tenant.delegate).
 * It holds no state and takes no decision: the tenant and the acting user are named on every call, OptiNexus verifies both, and
 * every answer that is not a success is translated by DelegationErrors. Reads may be repeated once on a transient failure; a
 * write is never repeated by the transport (a lost answer to a write is reported as `outcome_unknown`), except for the cases
 * where OptiNexus certainly did not process the request (renewed token, 429).
 */
class NexusDelegationClient
{
    public function __construct(private readonly NexusApi $api) {}

    /** The permission key OptiNexus knows for one of this application's codes. */
    public function key(string $code): string
    {
        return OptinexusSettings::applicationCode().'.'.$code;
    }

    /** This application's code for a key; a key of another application is returned as it is (and never matches the catalog). */
    public function code(string $key): string
    {
        $prefix = OptinexusSettings::applicationCode().'.';

        return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    /** @return array<string,mixed> */
    public function catalog(string $nexusTenant, string $actor): array
    {
        return $this->read($nexusTenant, $actor, '/catalog')['data'];
    }

    /** @return array{data:list<array<string,mixed>>,pagination:array<string,mixed>} */
    public function users(string $nexusTenant, string $actor, ?string $search, int $page, int $perPage): array
    {
        $answer = $this->read($nexusTenant, $actor, '/users', array_filter(['search' => $search, 'page' => $page, 'per_page' => $perPage], fn ($v) => $v !== null && $v !== ''));

        return ['data' => array_values((array) $answer['data']), 'pagination' => (array) ($answer['meta']['pagination'] ?? [])];
    }

    /**
     * @deprecated OptiNexus deprecates `POST /users` (contract §2b): a new person is invited with {@see self::invite()}, so that no password is
     *             ever typed by an Admin Tenant or passes through this application. Kept for compatibility only; the UI no longer calls it.
     *
     * @param  array{email:string,name:string,initial_password?:?string,role_ids?:list<string>}  $user
     * @return array<string,mixed> the user view with its `outcome` (created, linked, existing)
     */
    public function addUser(string $nexusTenant, string $actor, array $user): array
    {
        return $this->write('POST', $nexusTenant, $actor, '/users', $user)['data'];
    }

    /** @return array{data:list<array<string,mixed>>,pagination:array<string,mixed>} the invitations of this tenant and application, newest first */
    public function invitations(string $nexusTenant, string $actor, ?string $status, ?string $search, int $page, int $perPage): array
    {
        $answer = $this->read($nexusTenant, $actor, '/invitations', array_filter(['status' => $status, 'search' => $search, 'page' => $page, 'per_page' => $perPage], fn ($v) => $v !== null && $v !== ''));

        return ['data' => array_values((array) $answer['data']), 'pagination' => (array) ($answer['meta']['pagination'] ?? [])];
    }

    /**
     * OptiNexus stores the invitation and e-mails the link itself; the answer never carries the token.
     *
     * @param  array{email:string,name:string,role_ids:list<string>,locale:string}  $invitation
     * @return array<string,mixed> the invitation view
     */
    public function invite(string $nexusTenant, string $actor, array $invitation): array
    {
        return $this->write('POST', $nexusTenant, $actor, '/invitations', $invitation)['data'];
    }

    /** A new link and expiry, the old link stops working at once. @return array<string,mixed> */
    public function resendInvitation(string $nexusTenant, string $actor, string $id): array
    {
        return $this->write('POST', $nexusTenant, $actor, '/invitations/'.rawurlencode($id).'/resend')['data'];
    }

    /** @return array<string,mixed> */
    public function revokeInvitation(string $nexusTenant, string $actor, string $id, ?string $reason): array
    {
        return $this->write('POST', $nexusTenant, $actor, '/invitations/'.rawurlencode($id).'/revoke', $reason !== null && $reason !== '' ? ['reason' => $reason] : [])['data'];
    }

    /** @param list<string> $roleIds @return array<string,mixed> */
    public function setUserRoles(string $nexusTenant, string $actor, string $user, array $roleIds): array
    {
        return $this->write('PUT', $nexusTenant, $actor, "/users/{$user}/roles", ['role_ids' => $roleIds])['data'];
    }

    public function revokeUser(string $nexusTenant, string $actor, string $user): void
    {
        $this->write('DELETE', $nexusTenant, $actor, "/users/{$user}");
    }

    /** @param array{name:string,description?:?string,permissions:list<string>} $role @return array<string,mixed> */
    public function createRole(string $nexusTenant, string $actor, array $role): array
    {
        return $this->write('POST', $nexusTenant, $actor, '/roles', $role)['data'];
    }

    /** @param array<string,mixed> $role @return array<string,mixed> */
    public function updateRole(string $nexusTenant, string $actor, string $id, array $role): array
    {
        return $this->write('PUT', $nexusTenant, $actor, "/roles/{$id}", $role)['data'];
    }

    public function deleteRole(string $nexusTenant, string $actor, string $id): void
    {
        $this->write('DELETE', $nexusTenant, $actor, "/roles/{$id}");
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array{data:mixed,meta:array<string,mixed>}
     */
    private function read(string $nexusTenant, string $actor, string $path, array $query = []): array
    {
        $reference = $this->reference();

        // `acting_user_id` travels in the query of a GET (the transport sends `$data` of a GET as the query string).
        return $this->settle($this->guard(fn () => $this->api->send(
            'GET', $this->path($nexusTenant, $path), $query + ['acting_user_id' => $actor], repeatable: true, scopes: NexusApi::DELEGATION_SCOPES, correlationId: $reference,
        ), $reference, write: false), $reference, write: false);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{data:mixed,meta:array<string,mixed>}
     */
    private function write(string $method, string $nexusTenant, string $actor, string $path, array $body = []): array
    {
        $reference = $this->reference();
        // The contract puts `acting_user_id` in the query of GET and DELETE and in the body of everything else.
        $url = $this->path($nexusTenant, $path).($method === 'DELETE' ? '?'.http_build_query(['acting_user_id' => $actor]) : '');
        $payload = $method === 'DELETE' ? [] : $body + ['acting_user_id' => $actor];

        return $this->settle($this->guard(fn () => $this->api->send(
            $method, $url, $payload, repeatable: false, scopes: NexusApi::DELEGATION_SCOPES, correlationId: $reference,
        ), $reference, write: true), $reference, write: true);
    }

    private function path(string $nexusTenant, string $path): string
    {
        return '/delegation/tenants/'.rawurlencode($nexusTenant).$path;
    }

    /** One reference for the whole call: the id of the user's request, so Entry's audit and OptiNexus' audit share it. */
    private function reference(): string
    {
        $id = request()->attributes->get('request_id');

        return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
    }

    /** No answer at all (connection, timeout, 5xx after a repetition, refused credentials) becomes a DelegationFailure. */
    private function guard(callable $call, string $reference, bool $write): Response
    {
        try {
            return $call();
        } catch (IdentityProviderUnavailable $e) {
            if ($e->configuration) {
                throw new DelegationFailure('Delegation is not set up for this installation in OptiNexus.', 'DELEGATION_NOT_CONFIGURED', 503, ['reference' => $reference]);
            }

            throw new DelegationFailure(
                'OptiNexus is temporarily unavailable. Please try again shortly.',
                'IDENTITY_PROVIDER_UNAVAILABLE',
                503,
                ['reference' => $reference] + ($e->retryAfter !== null ? ['retry_after' => $e->retryAfter] : []) + ($write && $e->transient ? ['outcome_unknown' => true] : []),
            );
        }
    }

    /** @return array{data:mixed,meta:array<string,mixed>} */
    private function settle(Response $response, string $reference, bool $write): array
    {
        if (! $response->successful()) {
            DelegationErrors::fail($response, $reference, $write, fn (string $key) => $this->code($key));
        }

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? null) !== true || ! array_key_exists('data', $json)) {
            throw new DelegationFailure('OptiNexus gave an answer this application does not understand.', 'DELEGATION_UNEXPECTED_RESPONSE', 502, ['reference' => $reference, 'upstream_status' => $response->status()]);
        }

        return ['data' => $json['data'], 'meta' => (array) ($json['meta'] ?? [])];
    }
}
