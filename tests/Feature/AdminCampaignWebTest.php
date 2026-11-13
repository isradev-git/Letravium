<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MarketingConsent;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class AdminCampaignWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function communicationsAdmin()
    {
        return $this->admin(['admin_accounts' => 0, 'admin_communications' => 1]);
    }

    private function campaign($admin, string $status = Campaign::STATUS_DRAFT): Campaign
    {
        return Campaign::create([
            'subject' => 'Novedades del taller',
            'from_name' => 'Taller',
            'from_email' => 'taller@example.test',
            'body' => 'Contenido de prueba.',
            'status' => $status,
            'created_by' => $admin->id,
        ]);
    }

    private function consentedUser(): MarketingConsent
    {
        $user = $this->writer();

        return MarketingConsent::create([
            'user_id' => $user->id,
            'status' => MarketingConsent::STATUS_ACTIVE,
            'text_version' => '2027-01-v1',
            'confirmed_at' => now(),
        ]);
    }

    public function test_communications_admin_sees_the_campaign_screen_without_creative_data(): void
    {
        $admin = $this->communicationsAdmin();
        $this->campaign($admin);

        $writer = $this->writer();
        $universe = Universe::create(['owner_id' => $writer->id, 'name' => 'Arven']);
        Novel::create(['universe_id' => $universe->id, 'owner_id' => $writer->id, 'title' => 'Título privado ajeno']);

        $this->asAdmin($admin)
            ->get('/admin/campaigns')
            ->assertOk()
            ->assertSee('Campañas')
            ->assertSee('Novedades del taller')
            ->assertSee('action="/admin/campaigns?format=html"', false)
            ->assertDontSee('Título privado ajeno');
    }

    public function test_writer_and_accounts_only_admin_cannot_open_campaigns(): void
    {
        $writer = $this->writer();
        $accountsAdmin = $this->admin(['admin_accounts' => 1, 'admin_communications' => 0]);

        $this->asWriter($writer)->get('/admin/campaigns')->assertForbidden();
        $this->asAdmin($accountsAdmin)->get('/admin/campaigns')->assertForbidden();
    }

    public function test_html_draft_creation_redirects_and_json_is_unchanged(): void
    {
        $admin = $this->communicationsAdmin();

        $this->asAdmin($admin)
            ->post('/admin/campaigns?format=html', [
                'subject' => 'Carta de otoño',
                'from_name' => 'Taller',
                'from_email' => 'taller@example.test',
                'body' => 'Noticias del taller.',
            ])
            ->assertRedirect();

        $campaign = Campaign::where('subject', 'Carta de otoño')->firstOrFail();
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->status);

        $this->asAdmin($admin)
            ->postJson('/admin/campaigns', [
                'subject' => 'Carta de invierno',
                'from_name' => 'Taller',
                'from_email' => 'taller@example.test',
                'body' => 'Otra carta.',
            ])
            ->assertCreated()
            ->assertJsonPath('campaign.subject', 'Carta de invierno');
    }

    public function test_html_actions_start_pause_and_reconcile(): void
    {
        Mail::fake();
        $admin = $this->communicationsAdmin();
        $this->consentedUser();
        $campaign = $this->campaign($admin);

        $this->asAdmin($admin)
            ->post("/admin/campaigns/{$campaign->id}/start?format=html")
            ->assertRedirect("/admin/campaigns/{$campaign->id}");

        $campaign->refresh();
        $this->assertContains($campaign->status, [Campaign::STATUS_PAUSED, Campaign::STATUS_DONE]);
        $this->assertGreaterThan(0, $campaign->recipients()->count());

        $this->asAdmin($admin)
            ->post("/admin/campaigns/{$campaign->id}/pause?format=html")
            ->assertRedirect("/admin/campaigns/{$campaign->id}");

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->writer()->id,
            'email' => 'uncertain@example.test',
            'state' => CampaignRecipient::STATE_UNCERTAIN,
        ]);

        $this->asAdmin($admin)
            ->post("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile?format=html", [
                'state' => CampaignRecipient::STATE_ACCEPTED,
            ])
            ->assertRedirect("/admin/campaigns/{$campaign->id}");

        $this->assertSame(CampaignRecipient::STATE_ACCEPTED, $recipient->fresh()->state);
        $this->assertDatabaseHas('admin_audit_events', [
            'action' => 'campaign_recipient_reconciled',
            'subject_id' => $recipient->id,
        ]);
    }

    public function test_detail_shows_counts_recipients_and_reconcile_actions(): void
    {
        $admin = $this->communicationsAdmin();
        $campaign = $this->campaign($admin);
        $this->consentedUser();

        $uncertain = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->writer()->id,
            'email' => 'incierto@example.test',
            'state' => CampaignRecipient::STATE_UNCERTAIN,
        ]);

        $this->asAdmin($admin)
            ->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('Novedades del taller')
            ->assertSee('incierto@example.test')
            ->assertSee('Reconciliar')
            ->assertSee("/admin/campaigns/{$campaign->id}/recipients/{$uncertain->id}/reconcile?format=html", false);
    }
}
