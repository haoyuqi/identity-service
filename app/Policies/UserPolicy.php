<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveAdministrator($actor);
    }

    public function disable(User $actor, User $user): bool
    {
        return $this->isActiveAdministrator($actor)
            && $actor->isNot($user)
            && ($user->getAttributes()['disabled_at'] ?? null) === null;
    }

    private function isActiveAdministrator(User $user): bool
    {
        return $user->getRawOriginal('role') === UserRole::ADMIN->value
            && ($user->getAttributes()['disabled_at'] ?? null) === null;
    }
}
