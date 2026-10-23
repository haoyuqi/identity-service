<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticationService
{
    /**
     * Authenticate an enabled administrator and bind the password version to this session.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    public function authenticateAdministrator(array $credentials, Request $request): bool
    {
        $guard = Auth::guard('web');

        $authenticated = $guard->attemptWhen(
            $credentials,
            static fn (User $user): bool => $user->getRawOriginal('role') === UserRole::ADMIN->value
                && $user->disabled_at === null,
        );

        if (! $authenticated) {
            return false;
        }

        /** @var User $user */
        $user = $guard->user();

        // Capture immediately after login. A later password reset will make this marker stale,
        // including when it happens before the first authenticated HTTP request.
        $request->session()->put(
            'password_hash_web',
            $guard->hashPasswordForCookie($user->getAuthPassword()),
        );

        return true;
    }
}
