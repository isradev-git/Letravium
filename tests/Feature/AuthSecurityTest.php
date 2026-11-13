<?php

namespace Tests\Feature;

use App\Mail\SecurityNoticeMail;
use App\Models\CreativeMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $force = [], bool $creative = true): User
    {
        $user = User::factory()->create();

        if ($force !== []) {
            $user->forceFill($force)->save();
            $user->refresh();
        }

        if ($creative) {
            CreativeMembership::create(['user_id' => $user->id]);
        }

        return $user;
    }

    private function sessionKeys(): array
    {
        return [
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
        ];
    }

    private function otp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_home_shows_the_spanish_login(): void
    {
        $this->get('/')->assertOk()->assertSee('Entrar')->assertSee('Correo');
    }

    public function test_registration_creates_private_account_without_marketing(): void
    {
        Notification::fake();

        $response = $this->postJson('/register', [
            'name' => 'Elena',
            'email' => 'elena@example.com',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
        ]);

        $response->assertStatus(201)->assertJson(['status' => 'registered']);

        $user = User::where('email', 'elena@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->marketing_consent_at);
        $this->assertTrue($user->hasCreativeAccess());
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->actingAs($user)->getJson('/workspace')->assertStatus(403);
    }

    public function test_invited_registration_requires_matching_invitation(): void
    {
        $invitation = Invitation::create([
            'email' => 'invited@example.com',
            'token' => str_repeat('a', 64),
        ]);

        $this->postJson('/register', [
            'name' => 'Invitada',
            'email' => 'invited@example.com',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
            'invite_token' => $invitation->token,
        ])->assertStatus(201);

        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->postJson('/register', [
            'name' => 'Otra',
            'email' => 'otra@example.com',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
            'invite_token' => 'invalid',
        ])->assertStatus(422);
    }

    public function test_private_routes_require_verified_email_confirmed_factor_and_session_factor(): void
    {
        $confirmed = $this->makeUser(['totp_confirmed_at' => now()]);
        $this->actingAs($confirmed)->getJson('/workspace')->assertStatus(401);

        $unverified = User::factory()->unverified()->create();
        CreativeMembership::create(['user_id' => $unverified->id]);
        $this->actingAs($unverified)->withSession($this->sessionKeys())
            ->getJson('/workspace')->assertStatus(403);

        $this->actingAs($confirmed)->withSession($this->sessionKeys())
            ->getJson('/workspace')->assertOk();
    }

    public function test_enrollment_discloses_secret_and_confirms_first_code(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $secret = $this->getJson('/account/two-factor')->assertOk()->json('secret');
        $this->assertNotEmpty($secret);

        $response = $this->postJson('/account/two-factor', ['code' => $this->otp($secret)])
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        $this->assertCount(8, $response->json('recovery_codes'));
        $this->assertNotNull($user->fresh()->totp_confirmed_at);

        $raw = DB::table('users')->where('id', $user->id)->value('totp_secret');
        $this->assertNotSame($secret, $raw);
    }

    public function test_password_only_login_requires_factor_before_private_access(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ]);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJson(['status' => 'two_factor_required']);

        $this->getJson('/workspace')->assertStatus(401);

        $this->postJson('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertOk();

        $this->getJson('/workspace')->assertOk();
    }

    public function test_failed_html_login_does_not_flash_the_password(): void
    {
        $user = $this->makeUser();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertNull(session('_old_input.password'));
        $this->assertSame($user->email, session('_old_input.email'));
    }

    public function test_failed_login_does_not_redirect_to_a_foreign_referer(): void
    {
        $user = $this->makeUser();

        $this->withHeader('Referer', 'https://evil.test/phishing')
            ->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertRedirect('/login');
    }

    public function test_admin_without_creative_membership_lands_in_admin_after_factor(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
            'is_platform_admin' => true,
        ], creative: false);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/admin');

        $this->get('/admin')->assertOk();
    }

    public function test_writer_lands_in_workspace_after_factor(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $writer = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ]);

        $this->post('/login', ['email' => $writer->email, 'password' => 'password'])
            ->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertRedirect('/workspace');
    }

    public function test_totp_challenge_has_a_per_account_attempt_limit(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ]);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/two-factor/challenge', ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/two-factor/challenge', ['code' => $this->otp($secret)])
            ->assertStatus(429);
    }

    public function test_recovery_code_regeneration_sends_a_security_notice(): void
    {
        Mail::fake();
        $user = $this->makeUser([
            'email_verified_at' => now(),
            'totp_confirmed_at' => now(),
        ]);

        $this->actingAs($user)->withSession(array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]))
            ->postJson('/account/two-factor/recovery-codes')
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes');

        Mail::assertSent(SecurityNoticeMail::class, 1);
    }

    public function test_replayed_totp_is_rejected(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ]);
        $code = $this->otp($secret);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/two-factor/challenge', ['code' => $code])->assertOk();
        $this->postJson('/logout')->assertOk();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/two-factor/challenge', ['code' => $code])->assertStatus(422);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $secret = $this->getJson('/account/two-factor')->json('secret');
        $codes = $this->postJson('/account/two-factor', ['code' => $this->otp($secret)])
            ->json('recovery_codes');
        $code = $codes[0];

        $this->postJson('/logout')->assertOk();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/two-factor/challenge', ['code' => $code])->assertOk();
        $this->postJson('/logout')->assertOk();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/two-factor/challenge', ['code' => $code])->assertStatus(422);
    }

    public function test_password_reset_retains_factor_and_marketing_separation(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newlongpassword12',
            'password_confirmation' => 'newlongpassword12',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('newlongpassword12', $user->password));
        $this->assertSame($secret, $user->totp_secret);
        $this->assertNotNull($user->totp_confirmed_at);
        $this->assertNull($user->marketing_consent_at);
    }

    public function test_sessions_are_listed_and_revoked_only_for_owner(): void
    {
        $user = $this->makeUser(['totp_confirmed_at' => now()]);
        $other = $this->makeUser(['totp_confirmed_at' => now()]);

        DB::table('sessions')->insert([
            'id' => 'session-one',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $other->id,
            'ip_address' => '127.0.0.2',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $session = array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]);

        $this->actingAs($user)->withSession($session)
            ->getJson('/account/sessions')
            ->assertOk()
            ->assertJsonFragment(['id' => 'session-one']);

        $this->actingAs($user)->withSession($session)
            ->deleteJson('/account/sessions/other-session')
            ->assertStatus(404);

        $this->actingAs($user)->withSession($session)
            ->deleteJson('/account/sessions/session-one')
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'session-one']);
    }

    public function test_session_expiry_locks_idle_and_total_sessions(): void
    {
        $user = $this->makeUser(['totp_confirmed_at' => now()]);

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->subMinutes(31)->timestamp,
        ])->getJson('/workspace')->assertStatus(401);

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->subHours(13)->timestamp,
            'last_activity_at' => now()->timestamp,
        ])->getJson('/workspace')->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrongpassword',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(429);
    }

    public function test_admin_role_alone_has_no_creative_access(): void
    {
        $admin = $this->makeUser(['is_platform_admin' => true, 'totp_confirmed_at' => now()], false);
        $session = $this->sessionKeys();

        $this->actingAs($admin)->withSession($session)->getJson('/admin')->assertOk();
        $this->actingAs($admin)->withSession($session)->getJson('/workspace')->assertStatus(403);

        $this->actingAs($admin)->withSession($session)
            ->getJson('/admin/accounts')
            ->assertOk()
            ->assertJsonMissingPath('accounts.0.totp_secret');

        CreativeMembership::create(['user_id' => $admin->id]);
        $this->actingAs($admin)->withSession($session)->getJson('/workspace')->assertOk();
    }

    public function test_confirmed_factor_with_unverified_email_gets_a_verification_notice_instead_of_a_redirect_loop(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => null]);
        CreativeMembership::create(['user_id' => $user->id]);
        $user->forceFill(['totp_confirmed_at' => now()])->save();
        $session = ['totp_verified_at' => now()->timestamp, 'last_activity_at' => now()->timestamp];

        $this->actingAs($user)->withSession($session)
            ->get('/workspace')->assertRedirect('/account/verify-email');

        $this->actingAs($user)->withSession($session)
            ->get('/account/two-factor')->assertRedirect('/account/verify-email');

        $this->actingAs($user)->withSession($session)
            ->get('/account/verify-email')->assertOk()->assertSee('Verifica tu correo');

        $this->actingAs($user)->withSession($session)
            ->from('/account/verify-email')
            ->post('/account/verify-email/resend')
            ->assertRedirect('/account/verify-email');

        Notification::assertSentTo($user, VerifyEmail::class);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->withSession($session)->get($url)->assertRedirect('/account/two-factor');
        $user->refresh();

        $this->actingAs($user)->withSession($session)
            ->get('/workspace')->assertOk();
    }

    public function test_verification_link_marks_email_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_recent_auth_is_required_for_recovery_code_regeneration(): void
    {
        $user = $this->makeUser(['totp_confirmed_at' => now()]);

        $this->actingAs($user)->withSession($this->sessionKeys())
            ->postJson('/account/two-factor/recovery-codes')
            ->assertStatus(423);

        $this->actingAs($user)->withSession(array_merge(
            $this->sessionKeys(),
            ['reauth_at' => now()->timestamp],
        ))->postJson('/account/two-factor/recovery-codes')
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes');
    }

    private function confirmFirstFactor(User $user): array
    {
        $this->actingAs($user);
        $secret = $this->getJson('/account/two-factor')->json('secret');
        $codes = $this->postJson('/account/two-factor', ['code' => $this->otp($secret)])
            ->json('recovery_codes');

        return [$secret, $codes];
    }

    public function test_replacing_factor_keeps_active_factor_until_confirmation(): void
    {
        $user = $this->makeUser();
        [$oldSecret, $oldCodes] = $this->confirmFirstFactor($user);

        $session = array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]);

        $this->actingAs($user)->withSession($session)
            ->postJson('/account/two-factor/replace', [
                'password' => 'password',
                'code' => $oldCodes[0],
            ])
            ->assertOk()
            ->assertJson(['status' => 'replacement_pending_confirmation']);

        $user->refresh();
        $this->assertSame($oldSecret, $user->totp_secret);
        $this->assertNotNull($user->totp_confirmed_at);
        $this->assertNotNull($user->totp_pending_secret);
        $this->assertNotSame($oldSecret, $user->totp_pending_secret);
        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->id,
            'type' => 'two_factor_replaced',
        ]);
    }

    public function test_replacement_confirmation_promotes_new_secret_and_invalidates_old(): void
    {
        $user = $this->makeUser();
        [$oldSecret, $oldCodes] = $this->confirmFirstFactor($user);

        $session = array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]);

        $pending = $this->actingAs($user)->withSession($session)
            ->postJson('/account/two-factor/replace', [
                'password' => 'password',
                'code' => $oldCodes[0],
            ])->json('secret');

        $newCodes = $this->actingAs($user)->withSession($session)
            ->postJson('/account/two-factor', ['code' => $this->otp($pending)])
            ->assertOk()
            ->assertJson(['status' => 'confirmed'])
            ->json('recovery_codes');

        $user->refresh();
        $this->assertSame($pending, $user->totp_secret);
        $this->assertNull($user->totp_pending_secret);
        $this->assertNotNull($user->totp_confirmed_at);

        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJson(['status' => 'two_factor_required']);
        $this->postJson('/two-factor/challenge', ['code' => $this->otp($oldSecret)])->assertStatus(422);
        $this->postJson('/two-factor/challenge', ['code' => $oldCodes[1]])->assertStatus(422);
        $this->postJson('/two-factor/challenge', ['code' => $newCodes[0]])->assertOk();
    }

    public function test_replacement_pending_still_requires_old_factor_on_new_login(): void
    {
        $user = $this->makeUser();
        [$oldSecret, $oldCodes] = $this->confirmFirstFactor($user);

        $session = array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]);

        $this->actingAs($user)->withSession($session)
            ->postJson('/account/two-factor/replace', [
                'password' => 'password',
                'code' => $oldCodes[0],
            ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->totp_confirmed_at);
        $this->assertSame($oldSecret, $user->totp_secret);

        $this->postJson('/logout')->assertOk();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJson(['status' => 'two_factor_required']);

        $this->getJson('/account/two-factor')->assertStatus(401);
    }

    public function test_pending_replacement_seed_requires_recent_authorization(): void
    {
        $user = $this->makeUser();
        [, $oldCodes] = $this->confirmFirstFactor($user);

        $session = array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]);

        $this->actingAs($user)->withSession($session)
            ->postJson('/account/two-factor/replace', [
                'password' => 'password',
                'code' => $oldCodes[0],
            ])->assertOk();

        $this->flushSession();

        $this->actingAs($user)
            ->getJson('/account/two-factor')
            ->assertStatus(409)
            ->assertJsonMissingPath('secret');

        $this->actingAs($user)->withSession($session)
            ->getJson('/account/two-factor')
            ->assertOk()
            ->assertJsonPath('replacement', true)
            ->assertJsonStructure(['secret', 'otpauth_url']);
    }

    public function test_confirmed_user_cannot_read_enrollment_seed(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser(['totp_secret' => $secret, 'totp_confirmed_at' => now()]);

        $this->actingAs($user)
            ->getJson('/account/two-factor')
            ->assertStatus(409)
            ->assertJsonMissingPath('secret')
            ->assertJsonMissingPath('otpauth_url');

        $this->actingAs($user)->withSession($this->sessionKeys())
            ->getJson('/account/two-factor')
            ->assertStatus(409)
            ->assertJsonMissingPath('secret')
            ->assertJsonMissingPath('otpauth_url');
    }

    public function test_confirmed_user_cannot_renew_recovery_codes_through_enrollment_confirmation(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $old = [Hash::make('VIEJO1')];
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'last_totp_timestep' => intdiv(time(), 30) - 1,
            'recovery_codes' => $old,
        ]);

        $this->actingAs($user)
            ->postJson('/account/two-factor', ['code' => $this->otp($secret)])
            ->assertStatus(409)
            ->assertJsonMissingPath('recovery_codes');

        $this->assertSame($old, $user->fresh()->recovery_codes);
    }

    public function test_reused_totp_cannot_confirm_a_pending_factor(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->makeUser([
            'totp_secret' => $secret,
            'last_totp_timestep' => intdiv(time(), 30),
        ]);

        $this->actingAs($user)
            ->postJson('/account/two-factor', ['code' => $this->otp($secret)])
            ->assertStatus(422);
    }
}
