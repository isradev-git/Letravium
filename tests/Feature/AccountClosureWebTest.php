<?php

namespace Tests\Feature;

use App\Models\CreativeMembership;
use App\Models\MarketingConsent;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class AccountClosureWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function recent($user)
    {
        return $this->actingAs($user)->withSession([
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
            'reauth_at' => now()->timestamp,
        ]);
    }

    public function test_writer_sees_the_closure_page_with_export_and_retention_notes(): void
    {
        $user = $this->writer();

        $this->asWriter($user)
            ->get('/account/close')
            ->assertOk()
            ->assertSee('Cerrar cuenta')
            ->assertSee('href="/account/export?download=1"', false)
            ->assertSee('href="/workspace/backups"', false)
            ->assertSee('atribución')
            ->assertSee('respaldos');
    }

    public function test_export_can_download_the_account_record_without_changing_json(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);

        $download = $this->asWriter($user)->get('/account/export?download=1');

        $download->assertOk()
            ->assertJsonPath('owned.universes.0.name', 'Arven')
            ->assertJsonPath('owned.novels.0.title', 'La carta');

        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));

        $inline = $this->asWriter($user)->get('/account/export');
        $inline->assertOk()->assertJsonPath('owned.universes.0.name', 'Arven');
        $this->assertNull($inline->headers->get('content-disposition'));
    }

    public function test_wrong_confirmation_does_not_close_the_account(): void
    {
        $user = $this->writer(['username' => 'qa_writer']);

        $this->recent($user)
            ->post('/account/close?format=html', ['confirmation' => 'otra-cosa'])
            ->assertRedirect('/account/close')
            ->assertSessionHasErrors('confirmation');

        $this->assertNull($user->fresh()->closed_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_html_close_revokes_access_and_keeps_owned_content(): void
    {
        $user = $this->writer(['username' => 'qa_writer']);
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        MarketingConsent::create([
            'user_id' => $user->id,
            'status' => MarketingConsent::STATUS_ACTIVE,
            'text_version' => '2027-01-v1',
            'confirmed_at' => now(),
        ]);

        $this->recent($user)
            ->post('/account/close?format=html', ['confirmation' => 'qa_writer'])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Tu cuenta se cerró');

        $this->assertNotNull($user->fresh()->closed_at);
        $this->assertNull(CreativeMembership::where('user_id', $user->id)->first());
        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, MarketingConsent::where('user_id', $user->id)->value('status'));
        $this->assertDatabaseHas('novels', ['id' => $novel->id, 'title' => 'La carta']);
        $this->assertGuest();
    }

    public function test_close_requires_recent_authentication(): void
    {
        $user = $this->writer(['username' => 'qa_writer']);

        $this->asWriter($user)
            ->post('/account/close?format=html', ['confirmation' => 'qa_writer'])
            ->assertRedirect('/account/confirm-password');

        $this->assertNull($user->fresh()->closed_at);
    }

    public function test_json_close_contract_is_unchanged(): void
    {
        $user = $this->writer();

        $this->recent($user)
            ->postJson('/account/close')
            ->assertOk()
            ->assertJsonPath('closed', true);

        $this->assertNotNull($user->fresh()->closed_at);
    }
}
