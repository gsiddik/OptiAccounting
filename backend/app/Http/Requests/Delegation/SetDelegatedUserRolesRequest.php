<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

class SetDelegatedUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['role_ids' => ['present', 'array', 'max:20'], 'role_ids.*' => ['uuid']];
    }
}
