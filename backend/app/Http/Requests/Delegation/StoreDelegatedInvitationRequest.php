<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite a new person through OptiNexus (contract §2b). Neither the tenant, nor the inviter, nor a password or token is part of the request:
 * the tenant and the acting user come from the session, and OptiNexus e-mails the link and lets the invitee choose their own password.
 */
class StoreDelegatedInvitationRequest extends FormRequest
{
    /** The languages of the e-mail and of the OptiNexus page (the console's two languages). */
    public const LOCALES = ['en', 'id'];

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
            'role_ids' => ['sometimes', 'array', 'max:20'],
            'role_ids.*' => ['uuid'],
            'locale' => ['nullable', Rule::in(self::LOCALES)],
        ];
    }

    /** The language of the invitation: the one chosen in the console, else the browser's, else English (the contract's default). */
    public function invitationLocale(): string
    {
        $chosen = $this->validated()['locale'] ?? null;

        return is_string($chosen) ? $chosen : ($this->getPreferredLanguage(self::LOCALES) ?? 'en');
    }
}
