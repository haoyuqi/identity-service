<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_passwords_are_hashed_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create([
            'password' => 'plain-text-password',
        ]);

        $this->assertTrue(Hash::check('plain-text-password', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_user_email_verification_timestamp_is_cast_to_datetime(): void
    {
        $user = new User([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'plain-text-password',
        ]);

        $user->email_verified_at = '2026-10-23 00:00:00';

        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertSame('admin@example.test', $user->email);
        $this->assertNotSame('plain-text-password', $user->password);
    }

    public function test_user_email_is_normalized_and_new_accounts_are_members(): void
    {
        $user = User::factory()->create([
            'email' => '  Admin@Example.test  ',
        ]);

        $this->assertSame('admin@example.test', $user->email);
        $this->assertSame(UserRole::MEMBER, $user->role);
        $this->assertNull($user->disabled_at);
    }

    public function test_user_role_and_disabled_status_are_cast_and_factory_states_are_available(): void
    {
        $user = User::factory()->admin()->disabled()->create();

        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertInstanceOf(Carbon::class, $user->disabled_at);
    }

    public function test_disabled_at_preserves_the_instant_when_saved_from_a_non_utc_offset(): void
    {
        $timestamp = Carbon::parse('2026-10-20 12:30:00+09:00');
        $user = User::factory()->create();
        $user->created_at = $timestamp;
        $user->updated_at = $timestamp;
        $user->email_verified_at = $timestamp;
        $user->disabled_at = $timestamp;
        $user->save();
        $user->refresh();

        $databaseTimes = DB::selectOne(<<<'SQL'
            select
                to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as created_at,
                to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as updated_at,
                to_char(email_verified_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as email_verified_at,
                to_char(disabled_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as disabled_at
            from users
            where id = ?
            SQL, [$user->id]);

        $this->assertSame('2026-10-20 03:30:00', $databaseTimes->created_at);
        $this->assertSame($user->updated_at->getTimestamp(), Carbon::parse($databaseTimes->updated_at, 'UTC')->getTimestamp());
        $this->assertSame('2026-10-20 03:30:00', $databaseTimes->email_verified_at);
        $this->assertSame('2026-10-20 03:30:00', $databaseTimes->disabled_at);
        $this->assertSame(Carbon::parse('2026-10-20 03:30:00+00:00')->getTimestamp(), $user->disabled_at->getTimestamp());
    }

    public function test_database_seeder_does_not_create_default_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
