<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAdministrator
{
    public function __construct(private readonly AuthenticateSession $authenticateSession) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth('web');
        $user = $guard->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
        }

        if ($user->getRawOriginal('role') !== UserRole::ADMIN->value || $user->disabled_at !== null) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $marker = $request->session()->get('password_hash_web');
        // AuthenticateSession initializes absent markers for legacy sessions. Login creates
        // this marker itself, so a missing marker is rejected to close the reset-before-first-GET race.
        if (! is_string($marker) || $marker === '') {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $this->authenticateSession->handle($request, $next);
    }
}
