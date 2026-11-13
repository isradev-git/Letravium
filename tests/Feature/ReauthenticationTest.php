<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ReauthenticationTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function adminWithFactor(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'is_platform_admin' => true,
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ])->save();

        return [$admin->refresh(), $secret];
    }

    private function otp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function sessionKeys(): array
    {
        return [
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
        ];
    }

    public function test_recent_auth_form_completes_and_returns_to_the_page(): void
    {
        [$admin, $secret] = $this->adminWithFactor();
        $writer = $this->writer();

        $this->actingAs($admin)->withSession($this->sessionKeys())
            ->withHeader('Referer', url('/admin/accounts'))
            ->post("/admin/accounts/{$writer->id}/quota", ['_method' => 'PATCH', 'bytes_limit' => 1024])
            ->assertRedirect('/account/confirm-password');

        $this->get('/account/confirm-password')
            ->assertOk()
            ->assertSee('Confirmar acceso')
            ->assertSee('name="password"', false)
            ->assertSee('name="code"', false);

        $this->from('/account/confirm-password')
            ->post('/account/confirm-password', ['password' => 'password', 'code' => 'not-a-code'])
            ->assertSessionHasErrors('password');

        $this->assertNull(session('reauth_at'));

        $this->post('/account/confirm-password', ['password' => 'password', 'code' => $this->otp($secret)])
            ->assertRedirect('/admin/accounts');

        $this->assertNotNull(session('reauth_at'));

        $this->withHeader('Referer', url('/admin/accounts'))
            ->post("/admin/accounts/{$writer->id}/quota", ['_method' => 'PATCH', 'bytes_limit' => 1024])
            ->assertRedirect('/admin/accounts');

        $this->assertDatabaseHas('account_quotas', ['user_id' => $writer->id, 'bytes_limit' => 1024]);
    }

    public function test_fresh_factor_challenge_counts_as_recent_authentication(): void
    {
        [$admin, $secret] = $this->adminWithFactor();
        $writer = $this->writer();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/admin');

        $this->post("/admin/accounts/{$writer->id}/quota", ['_method' => 'PATCH', 'bytes_limit' => 4096])
            ->assertRedirect('/admin/accounts');

        $this->assertDatabaseHas('account_quotas', ['user_id' => $writer->id, 'bytes_limit' => 4096]);
    }

    public function test_protocol_relative_return_target_is_not_followed(): void
    {
        [$admin, $secret] = $this->adminWithFactor();

        $this->actingAs($admin)->withSession(array_merge($this->sessionKeys(), [
            'reauth_return' => '//evil.test/phishing',
        ]))->post('/account/confirm-password', [
            'password' => 'password',
            'code' => $this->otp($secret),
        ])->assertRedirect('/admin');
    }

    public function test_foreign_referer_is_not_used_as_a_return_target(): void
    {
        [$admin, $secret] = $this->adminWithFactor();
        $writer = $this->writer();

        $this->actingAs($admin)->withSession($this->sessionKeys())
            ->withHeader('Referer', 'https://evil.test/phishing')
            ->post("/admin/accounts/{$writer->id}/quota", ['_method' => 'PATCH', 'bytes_limit' => 1])
            ->assertRedirect('/account/confirm-password');

        $this->post('/account/confirm-password', ['password' => 'password', 'code' => $this->otp($secret)])
            ->assertRedirect('/admin');
    }
}
