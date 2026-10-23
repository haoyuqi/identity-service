<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class DisableUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && ($this->user()?->can('disable', $user) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
