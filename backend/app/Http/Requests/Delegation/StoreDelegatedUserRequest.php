<?php

namespace App\Http\Requests\Delegation;

use App\Domain\AccessControl\Models\DataScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create or link a person through OptiNexus. The tenant is never part of the request; it comes from the session.
 *
 * @deprecated Compatibility only (OptiNexus deprecates `POST /users`, contract §2b). The console invites a new person instead
 *             (StoreDelegatedInvitationRequest), so no password is typed by an Admin Tenant. `initial_password` is still accepted here, as
 *             documented, and is not part of any new screen.
 */
class StoreDelegatedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            // Deprecated. Only used when OptiNexus creates a brand-new identity; it is passed on and never stored or logged here.
            'initial_password' => ['nullable', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()],
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['uuid'],
            'data_scopes' => ['sometimes', 'array', 'max:20'],
            'data_scopes.*.scope_type' => ['required', Rule::in(DataScope::TYPES)],
            'data_scopes.*.branch_id' => ['nullable', 'uuid'],
            'data_scopes.*.business_unit_id' => ['nullable', 'uuid'],
        ];
    }
}
