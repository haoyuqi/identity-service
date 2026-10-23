<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(private readonly AuthenticationService $authenticationService) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! $this->authenticationService->authenticateAdministrator($request->credentials(), $request)) {
            return back()
                ->withErrors(['email' => 'The provided credentials are invalid.'])
                ->onlyInput('email');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function dashboard(): View
    {
        return view('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        auth('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
