<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class LoginRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_against_one_account_do_not_block_another_account(): void
    {
        $blocked = $this->administrator('blocked@example.test');
        $untouched = $this->administrator('untouched@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $blocked->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');

            Auth::forgetGuards();
        }

        $this->post(route('login.store'), [
            'email' => $blocked->email,
            'password' => 'correct-password',
        ])->assertTooManyRequests();

        Auth::forgetGuards();

        $this->post(route('login.store'), [
            'email' => $untouched->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_account_limiter_normalizes_the_email_before_counting(): void
    {
        $administrator = $this->administrator('normalized@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => '  '.mb_strtoupper($administrator->email).'  ',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');

            Auth::forgetGuards();
        }

        $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'correct-password',
        ])->assertTooManyRequests();
    }

    public function test_address_limiter_caps_spraying_across_many_accounts(): void
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'spray-'.$attempt.'@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');

            Auth::forgetGuards();
        }

        $this->post(route('login.store'), [
            'email' => 'spray-final@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_throttled_login_response_does_not_reveal_account_state(): void
    {
        $administrator = $this->administrator('throttled@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $administrator->email,
                'password' => 'wrong-password',
            ]);

            Auth::forgetGuards();
        }

        $this->post(route('login.store'), [
            'email' => $administrator->email,
            'password' => 'correct-password',
        ])->assertTooManyRequests()
            ->assertDontSeeText($administrator->email);
    }

    private function administrator(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::ADMIN,
            'disabled_at' => null,
            'password' => 'correct-password',
        ]);
    }
}
