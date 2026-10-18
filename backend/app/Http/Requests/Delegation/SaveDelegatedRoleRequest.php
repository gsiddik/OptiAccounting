<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

/** Create (all fields required) or edit (any subset) a custom role. Permission codes are this application's; OptiNexus decides which are delegable. */
class SaveDelegatedRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $some = $create ? 'required' : 'sometimes';

        return [
            'name' => [$some, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => [$some, 'array', 'min:1', 'max:200'],
            'permissions.*' => ['string', 'max:150'],
        ];
    }
}
