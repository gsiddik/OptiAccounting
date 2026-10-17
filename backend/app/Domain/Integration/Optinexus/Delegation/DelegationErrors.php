<?php

namespace App\Domain\Integration\Optinexus\Delegation;

use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;

/**
 * Turns every answer of the OptiNexus delegation API that is not a success into one DelegationFailure (or a standard 422
 * validation error), so a screen never has to understand OptiNexus' envelope. Nothing is guessed: a code that is not listed
 * here is reported as an unexpected answer with its HTTP status and the correlation reference, never as success.
 *
 * Codes OptiNexus defines (docs/ecosystem/GOVERNANCE_CONTRACTS.md §2) pass through unchanged, because they are already stable
 * and meaningful to a person: the refusal is a decision of OptiNexus and is shown as one. Installation problems (service
 * account not bound, not allowed to delegate, application not delegable, tenant of another binding) become
 * DELEGATION_NOT_CONFIGURED, because only an operator can cure them.
 */
final class DelegationErrors
{
    /** @var array<string,int> codes that pass through, with the status they are answered with here */
    private const PASS_THROUGH = [
        'ACTOR_NOT_TENANT_ADMIN' => 403,
        'TENANT_NOT_ACTIVE' => 403,
        'APPLICATION_NOT_ACTIVE' => 403,
        'APPLICATION_NOT_SUBSCRIBED' => 403,
        'PERMISSION_NOT_DELEGABLE' => 422,
        'ROLE_NOT_ASSIGNABLE' => 422,
        'ROLE_NOT_EDITABLE' => 404,
        'ROLE_IN_USE' => 409,
        'USER_NOT_IN_TENANT' => 404,
        'TENANT_ADMIN_PROTECTED' => 409,
        'IDENTITY_NOT_LINKABLE' => 409,
        'MEMBERSHIP_NOT_ACTIVE' => 409,
        'SELF_REVOCATION_DENIED' => 409,
    ];

    /** @var list<string> */
    private const NOT_CONFIGURED = ['SERVICE_ACCOUNT_NOT_BOUND', 'APPLICATION_NOT_DELEGABLE', 'TENANT_MISMATCH', 'UNAUTHORIZED', 'UNAUTHENTICATED', 'FORBIDDEN'];

    /**
     * @param  callable(string):string  $toLocalCode  maps an OptiNexus permission key to this application's code (and keeps foreign keys as they are)
     *
     * @throws DelegationFailure
     * @throws ValidationException
     */
    public static function fail(Response $response, string $reference, bool $write, callable $toLocalCode): never
    {
        $status = $response->status();
        $code = (string) $response->json('error.code');
        $message = (string) ($response->json('error.message') ?? '');
        $details = $response->json('error.details');
        $details = is_array($details) ? $details : [];
        $base = ['reference' => $reference];

        if ($status === 422 && $code === 'VALIDATION_ERROR') {
            throw ValidationException::withMessages(array_map(fn ($messages) => (array) $messages, $details));
        }

        if (isset(self::PASS_THROUGH[$code])) {
            throw new DelegationFailure($message !== '' ? $message : $code, $code, self::PASS_THROUGH[$code], $base + self::refused($details, $toLocalCode), $code);
        }

        if (in_array($code, self::NOT_CONFIGURED, true) && in_array($status, [401, 403], true)) {
            throw new DelegationFailure('Delegation is not set up for this installation in OptiNexus.', 'DELEGATION_NOT_CONFIGURED', 503, $base, $code);
        }

        if ($status === 404 && $code === 'RESOURCE_NOT_FOUND') {
            throw new DelegationFailure('OptiNexus does not know this organization.', 'DELEGATION_TENANT_UNKNOWN', 409, $base, $code);
        }

        if ($status === 429) {
            throw new DelegationFailure('OptiNexus is busy. Please try again shortly.', 'IDENTITY_PROVIDER_UNAVAILABLE', 503, $base + ['retry_after' => self::retryAfter($response)], $code ?: null);
        }

        if ($status >= 500) {
            // A write may or may not have been applied: say so, instead of letting the person repeat it blindly.
            throw new DelegationFailure('OptiNexus could not complete the request.', 'IDENTITY_PROVIDER_UNAVAILABLE', 503, $base + ($write ? ['outcome_unknown' => true] : []), $code ?: null);
        }

        throw new DelegationFailure('OptiNexus gave an answer this application does not understand.', 'DELEGATION_UNEXPECTED_RESPONSE', 502, $base + ['upstream_status' => $status], $code ?: null);
    }

    /** The permissions and roles OptiNexus refused, in this application's codes. @return array<string,mixed> */
    private static function refused(array $details, callable $toLocalCode): array
    {
        $out = [];
        if (isset($details['permissions']) && is_array($details['permissions'])) {
            $out['permissions'] = array_values(array_map(fn ($key) => $toLocalCode((string) $key), $details['permissions']));
        }
        if (isset($details['role_ids']) && is_array($details['role_ids'])) {
            $out['role_ids'] = array_values(array_map('strval', $details['role_ids']));
        }

        return $out;
    }

    private static function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : 30;
    }
}
