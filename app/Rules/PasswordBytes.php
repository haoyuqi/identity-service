<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reject passwords that bcrypt would silently truncate.
 */
final class PasswordBytes implements ValidationRule
{
    public const MAX_BYTES = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_BYTES) {
            $fail('The password must not exceed '.self::MAX_BYTES.' bytes.');
        }
    }
}
