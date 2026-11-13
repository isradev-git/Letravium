<?php

namespace Tests\Feature;

use App\Models\CreativeMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function writerWithFactor(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ])->save();
        CreativeMembership::create(['user_id' => $user->id]);

        return [$user->refresh(), $secret];
    }

    private function otp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_login_form_offers_remember_me(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="remember"', false)
            ->assertSee('Recordarme en este equipo');
    }

    public function test_remember_choice_survives_the_totp_challenge(): void
    {
        [$user, $secret] = $this->writerWithFactor();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/workspace');

        $this->assertTrue(session('auth_remember'));
    }

    public function test_remembered_idle_session_renews_with_factor_only(): void
    {
        [$user, $secret] = $this->writerWithFactor();
        $startedAt = now()->subHours(2)->timestamp;

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->subMinutes(31)->timestamp,
            'auth_remember' => true,
            'session_started_at' => $startedAt,
        ])->get('/workspace')->assertRedirect('/two-factor/challenge');

        $this->assertSame($user->id, session('pending_user_id'));
        $this->assertTrue(session('pending_remember'));
        $this->assertSame($startedAt, session('pending_started_at'));

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect(url('/workspace'));

        $this->assertSame($startedAt, session('session_started_at'));

        $this->get('/workspace')->assertOk();
    }

    public function test_factor_only_unlock_does_not_count_as_recent_password_authentication(): void
    {
        [$user, $secret] = $this->writerWithFactor();

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->subMinutes(31)->timestamp,
            'auth_remember' => true,
        ])->get('/workspace')->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect(url('/workspace'));

        $this->assertNull(session('reauth_at'));

        $this->post('/account/two-factor/recovery-codes')
            ->assertRedirect('/account/confirm-password');
    }

    public function test_idle_session_without_remember_is_sent_to_full_login(): void
    {
        [$user] = $this->writerWithFactor();

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->subMinutes(31)->timestamp,
            'auth_remember' => false,
        ])->get('/workspace')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(session('pending_user_id'));
    }

    public function test_twelve_hour_expiry_forces_full_login_even_with_remember(): void
    {
        [$user] = $this->writerWithFactor();

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->subHours(13)->timestamp,
            'last_activity_at' => now()->timestamp,
            'auth_remember' => true,
        ])->get('/workspace')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(session('pending_user_id'));
    }
}
