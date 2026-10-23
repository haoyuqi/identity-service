<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserModelTest extends TestCase
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

    public function test_database_seeder_does_not_create_default_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
