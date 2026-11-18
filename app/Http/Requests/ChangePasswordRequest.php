<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /** Accept common frontend aliases without weakening validation. */
    protected function prepareForValidation(): void
    {
        if ($this->has('currentPassword') && ! $this->has('current_password')) {
            $this->merge(['current_password' => $this->input('currentPassword')]);
        }

        if ($this->has('new_password') && ! $this->has('password')) {
            $this->merge(['password' => $this->input('new_password')]);
        }

        if ($this->has('new_password_confirmation') && ! $this->has('password_confirmation')) {
            $this->merge(['password_confirmation' => $this->input('new_password_confirmation')]);
        }
    }
}
