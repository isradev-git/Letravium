<?php

namespace Tests\Feature;

use App\Models\MarketingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class AccountWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_sessions_page_lists_sessions_and_revokes_another_one(): void
    {
        $user = $this->writer();
        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Navegador de prueba',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->asWriter($user)
            ->get('/account/sessions')
            ->assertOk()
            ->assertSee('Sesiones y dispositivos')
            ->assertSee('192.0.2.10')
            ->assertSee('/account/sessions/other-session', false);

        $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
            'reauth_at' => now()->timestamp,
        ])->delete('/account/sessions/other-session')
            ->assertRedirect('/account/sessions');

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_marketing_preferences_page_requests_confirms_and_withdraws_consent(): void
    {
        $user = $this->writer();

        $this->asWriter($user)
            ->get('/account/marketing')
            ->assertOk()
            ->assertSee('Comunicaciones comerciales')
            ->assertSee('Solicitar consentimiento');

        $this->asWriter($user)
            ->post('/account/marketing/request')
            ->assertRedirect('/account/marketing');

        $consent = MarketingConsent::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MarketingConsent::STATUS_PENDING, $consent->status);

        $this->asWriter($user)
            ->post('/account/marketing/confirm', ['token' => $consent->token])
            ->assertRedirect('/account/marketing');

        $this->assertSame(MarketingConsent::STATUS_ACTIVE, $consent->fresh()->status);

        $this->asWriter($user)
            ->post('/account/marketing/withdraw')
            ->assertRedirect('/account/marketing');

        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $consent->fresh()->status);
    }
}
