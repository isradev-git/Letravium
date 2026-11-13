<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_links_to_a_forgot_password_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('href="/forgot-password"', false);

        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Recuperar contraseña')
            ->assertSee('action="/forgot-password"', false);
    }

    public function test_forgot_password_sends_a_rendered_reset_link(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status');

        $this->post('/forgot-password', ['email' => 'missing@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status');
    }

    public function test_reset_form_changes_the_password_and_keeps_the_factor(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_confirmed_at' => now(),
        ])->save();
        $secret = $user->fresh()->totp_secret;
        $token = Password::createToken($user);

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertSee('Nueva contraseña')
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$user->email.'"', false);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nueva-clave-larga-2026',
            'password_confirmation' => 'nueva-clave-larga-2026',
        ])->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue(Hash::check('nueva-clave-larga-2026', $user->password));
        $this->assertSame($secret, $user->totp_secret);
        $this->assertNotNull($user->totp_confirmed_at);
    }

    public function test_auth_emails_use_spanish_copy(): void
    {
        $user = User::factory()->create();

        $reset = (new ResetPassword('token'))->toMail($user);
        $this->assertSame('Restablece tu contraseña', $reset->subject);
        $this->assertSame('Restablecer contraseña', $reset->actionText);
        $this->assertStringContainsString('Recibiste este correo', implode(' ', $reset->introLines));

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('Verifica tu correo', $verify->subject);
        $this->assertSame('Verificar correo', $verify->actionText);

        $this->assertSame('Hola:', __('Hello!'));
        $this->assertSame('Todos los derechos reservados.', __('All rights reserved.'));
        $this->assertSame('Espacio de escritura', config('app.name'));
    }
}
