<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisableUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\UserManagementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()->orderBy('id')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', [
            'roles' => UserRole::cases(),
        ]);
    }

    public function store(StoreUserRequest $request, UserManagementService $userManagement): RedirectResponse
    {
        $data = $request->accountData();
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $user = $userManagement->createAccount(
            $data['name'],
            $data['email'],
            UserRole::from($data['role']),
            $data['password'],
            $actor,
        );

        return redirect()->route('admin.users.show', $user)->with('status', 'Account created.');
    }

    public function show(User $user): View
    {
        Gate::authorize('view', $user);

        return view('admin.users.show', [
            'user' => $user,
            'auditLogs' => AdminAuditLog::query()
                ->where('target_user_id', $user->getKey())
                ->latest('created_at')
                ->paginate(25),
        ]);
    }

    public function disable(
        DisableUserRequest $request,
        User $user,
        UserManagementService $userManagement,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $userManagement->disableAccount($user, $actor);
        } catch (DomainException $exception) {
            return back()->withErrors(['account' => $exception->getMessage()]);
        }

        return redirect()->route('admin.users.show', $user)->with('status', 'Account disabled.');
    }
}
