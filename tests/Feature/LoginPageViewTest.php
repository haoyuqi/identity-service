<?php

namespace Tests\Feature;

use Tests\TestCase;

final class LoginPageViewTest extends TestCase
{
    public function test_login_page_uses_same_origin_stylesheet_without_inline_styles_or_scripts(): void
    {
        $stylesheet = public_path('css/auth.css');
        $this->assertFileExists($stylesheet);
        $this->assertNotEmpty(file_get_contents($stylesheet));

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('Identity Service')
            ->assertSee('Administrator sign in')
            ->assertSee('<link rel="stylesheet" href="/css/auth.css">', false)
            ->assertDontSee('<style', false)
            ->assertDontSee('style="', false)
            ->assertDontSee('<script', false)
            ->assertDontSee('class="has-error"', false)
            ->assertDontSee('role="alert"', false);
    }

    public function test_login_page_renders_accessible_error_state_when_validation_errors_exist(): void
    {
        $response = $this->followingRedirects()
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'not-an-email',
                'password' => 'secret',
            ]);

        $response->assertOk()
            ->assertSee('class="has-error"', false)
            ->assertSee('role="alert"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="not-an-email"', false);
    }
}
