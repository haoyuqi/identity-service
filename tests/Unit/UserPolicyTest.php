<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use PHPUnit\Framework\TestCase;

final class UserPolicyTest extends TestCase
{
    public function test_active_administrators_can_manage_accounts(): void
    {
        $policy = new UserPolicy;
        $actor = $this->user(UserRole::ADMIN, id: 1);
        $target = $this->user(UserRole::MEMBER, id: 2);

        self::assertTrue($policy->viewAny($actor));
        self::assertTrue($policy->view($actor, $target));
        self::assertTrue($policy->create($actor));
        self::assertTrue($policy->disable($actor, $target));
    }

    public function test_members_and_disabled_administrators_cannot_manage_accounts(): void
    {
        $policy = new UserPolicy;
        $member = $this->user(UserRole::MEMBER, id: 1);
        $disabledAdmin = $this->user(UserRole::ADMIN, '2026-10-01T00:00:00Z', 2);
        $target = $this->user(UserRole::MEMBER, id: 3);

        foreach ([$member, $disabledAdmin] as $actor) {
            self::assertFalse($policy->viewAny($actor));
            self::assertFalse($policy->view($actor, $target));
            self::assertFalse($policy->create($actor));
            self::assertFalse($policy->disable($actor, $target));
        }
    }

    public function test_administrator_cannot_disable_self_or_an_already_disabled_account(): void
    {
        $policy = new UserPolicy;
        $actor = $this->user(UserRole::ADMIN, id: 1);
        $disabledTarget = $this->user(UserRole::MEMBER, '2026-10-01T00:00:00Z', 2);

        self::assertFalse($policy->disable($actor, $actor));
        self::assertFalse($policy->disable($actor, $disabledTarget));
    }

    private function user(UserRole $role, ?string $disabledAt = null, ?int $id = null): User
    {
        $user = new User;
        $user->setRawAttributes([
            'role' => $role->value,
            'disabled_at' => $disabledAt,
            'id' => $id,
        ], true);

        return $user;
    }
}
