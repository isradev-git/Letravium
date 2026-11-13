<?php

namespace Tests\Feature;

use App\Models\CreativeMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $force = [], bool $creative = true): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(array_merge([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ], $force))->save();

        if ($creative) {
            CreativeMembership::create(['user_id' => $user->id]);
        }

        return [$user->refresh(), $secret];
    }

    private function otp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_a_writer_can_log_in_with_a_username(): void
    {
        [$user, $secret] = $this->user(['username' => 'usuario_test']);

        $this->post('/login', ['email' => 'usuario_test', 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/workspace');

        $this->get('/workspace')->assertOk();
    }

    public function test_a_platform_admin_can_log_in_with_a_username(): void
    {
        [$admin, $secret] = $this->user([
            'username' => 'iz_terminal',
            'is_platform_admin' => true,
        ], creative: false);

        $this->post('/login', ['email' => 'iz_terminal', 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/admin');

        $this->get('/admin')->assertOk();
    }

    public function test_email_login_still_works(): void
    {
        [$user, $secret] = $this->user(['username' => 'usuario_test']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/workspace');
    }

    public function test_an_unknown_identifier_is_rejected(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nadie', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }
}
