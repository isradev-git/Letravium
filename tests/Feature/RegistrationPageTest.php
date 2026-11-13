<?php

namespace Tests\Feature;

use App\Models\AccountQuota;
use App\Models\CreativeMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class RegistrationPageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_login_links_to_an_accessible_registration_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('href="/register"', false)
            ->assertSee('Crear una cuenta');

        $this->get('/register')
            ->assertOk()
            ->assertSee('Crear una cuenta')
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_html_registration_creates_private_account_and_continues_to_totp(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Elena',
            'email' => 'nueva@example.invalid',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
        ])->assertRedirect('/account/two-factor');

        $user = User::where('email', 'nueva@example.invalid')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->marketing_consent_at);
        $this->assertTrue(CreativeMembership::where('user_id', $user->id)->exists());
        $this->assertDatabaseHas('account_quotas', [
            'user_id' => $user->id,
            'bytes_limit' => AccountQuota::DEFAULT_BYTES,
        ]);

        $this->get('/workspace')->assertRedirect('/account/two-factor');
    }

    public function test_invalid_registration_returns_to_the_form_with_errors(): void
    {
        $this->followingRedirects()
            ->from('/register')
            ->post('/register', [
                'name' => 'E',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertOk()
            ->assertSee('No se pudo crear')
            ->assertSee('role="alert"', false)
            ->assertSee('El correo no es válido.')
            ->assertSee('La contraseña debe tener al menos 12 caracteres.');

        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }

    public function test_invitation_token_is_prefilled_and_accepted(): void
    {
        Notification::fake();
        $invitation = Invitation::create(['email' => 'invitada@example.invalid', 'token' => 'invite-token-123']);

        $this->get('/register?invite=invite-token-123')
            ->assertOk()
            ->assertSee('value="invite-token-123"', false);

        $this->post('/register', [
            'name' => 'Invitada',
            'email' => 'invitada@example.invalid',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
            'invite_token' => 'invite-token-123',
        ])->assertRedirect('/account/two-factor');

        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_wrong_invitation_token_is_rejected_without_creating_an_account(): void
    {
        Notification::fake();
        Invitation::create(['email' => 'invitada@example.invalid', 'token' => 'correct-token']);

        $this->from('/register')
            ->post('/register', [
                'name' => 'Invitada',
                'email' => 'invitada@example.invalid',
                'password' => 'longpassword12',
                'password_confirmation' => 'longpassword12',
                'invite_token' => 'wrong-token',
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('invite_token');

        $this->assertDatabaseMissing('users', ['email' => 'invitada@example.invalid']);
    }

    public function test_signed_in_users_do_not_see_registration(): void
    {
        $this->actingAs($this->writer())->get('/register')->assertRedirect('/workspace');
        $this->actingAs($this->admin())->get('/register')->assertRedirect('/admin');
    }
}
