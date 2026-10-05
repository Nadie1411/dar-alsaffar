<?php

namespace App\Http\Requests\Panel;

use App\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Addresses are stored in lower case, and the database compares them as written. */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email', '')))]);
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($member?->id)],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'is_active' => ['nullable', 'boolean'],
            // Required for someone new; for an existing member, only when it is to change.
            'password' => [$member === null ? 'required' : 'nullable', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }
}
