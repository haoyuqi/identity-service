<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');
        $normalized = [];

        if (is_string($name)) {
            $normalized['name'] = trim($name);
        }

        if (is_string($email)) {
            $normalized['email'] = mb_strtolower(trim($email));
        }

        $this->merge($normalized);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => [
                'required',
                'string',
                'min:12',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ];
    }

    /** @return array{name: string, email: string, role: string, password: string} */
    public function accountData(): array
    {
        /** @var array{name: string, email: string, role: string, password: string} $data */
        $data = $this->validated();

        return $data;
    }
}
