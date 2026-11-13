<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_login_without_a_separate_public_landing(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('action="/login"', false)
            ->assertSee('for="email"', false)
            ->assertDontSee('Una historia merece');
    }

    public function test_home_sends_signed_in_users_to_their_app_area(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/workspace');

        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get('/')
            ->assertRedirect('/admin');
    }

    public function test_existing_public_and_access_pages_use_the_shared_visual_layout(): void
    {
        foreach (['/', '/login', '/two-factor/challenge'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('class="site-shell"', false)
                ->assertSee('rel="stylesheet"', false)
                ->assertSee('<main', false);
        }

        $this->actingAs(User::factory()->create())
            ->get('/account/two-factor')
            ->assertOk()
            ->assertSee('class="site-shell"', false)
            ->assertSee('rel="stylesheet"', false)
            ->assertSee('<main', false);
    }

    public function test_access_forms_keep_visible_labels_and_error_messages(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'demo@example.invalid',
            'password' => 'incorrect-password',
        ])->assertRedirect('/login');

        $this->get('/login')
            ->assertSee('for="email"', false)
            ->assertSee('for="password"', false)
            ->assertSee('role="alert"', false);

        $this->from('/two-factor/challenge')->post('/two-factor/challenge', [
            'code' => '000000',
        ])->assertRedirect('/two-factor/challenge');

        $this->get('/two-factor/challenge')
            ->assertSee('for="code"', false)
            ->assertSee('role="alert"', false)
            ->assertDontSee('inputmode="numeric"', false);
    }
}
