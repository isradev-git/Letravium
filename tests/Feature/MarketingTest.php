<?php

namespace Tests\Feature;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MarketingConsent;
use App\Models\User;
use App\Services\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $force = [], bool $admin = false): User
    {
        $user = User::factory()->create();

        $user->forceFill(array_merge([
            'totp_confirmed_at' => now(),
            'is_platform_admin' => $admin,
        ], $force))->save();

        return $user->refresh();
    }

    private function privateSession(User $user, bool $recent = true): static
    {
        $session = [
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
        ];

        if ($recent) {
            $session['reauth_at'] = now()->timestamp;
        }

        return $this->actingAs($user)->withSession($session);
    }

    private function consent(User $user, string $status, array $extra = []): MarketingConsent
    {
        return MarketingConsent::create(array_merge([
            'user_id' => $user->id,
            'status' => $status,
            'token' => Str::random(64),
            'text_version' => config('marketing.consent_text_version'),
            'source' => 'test',
            'requested_at' => now(),
        ], $extra));
    }

    private function campaign(User $admin, array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'subject' => 'Novedades',
            'from_name' => 'App',
            'from_email' => 'news@example.com',
            'body' => '<p>Novedades</p>',
            'status' => Campaign::STATUS_DRAFT,
            'created_by' => $admin->id,
        ], $overrides));
    }

    public function test_registration_without_marketing_consent_keeps_full_functions(): void
    {
        Notification::fake();

        $this->postJson('/register', [
            'name' => 'Elena',
            'email' => 'elena@example.com',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
        ])->assertStatus(201);

        $user = User::where('email', 'elena@example.com')->firstOrFail();

        $this->assertDatabaseMissing('marketing_consents', ['user_id' => $user->id]);
        $this->assertNull($user->marketing_consent_at);
        $this->assertTrue($user->hasCreativeAccess());

        $user->forceFill(['email_verified_at' => now(), 'totp_confirmed_at' => now()])->save();

        $this->privateSession($user->fresh())->getJson('/workspace')->assertOk();
    }

    public function test_campaign_preview_counts_only_active_consents(): void
    {
        $admin = $this->makeUser([], true);
        $active = $this->makeUser();
        $pending = $this->makeUser();
        $withdrawn = $this->makeUser();

        $this->consent($active, MarketingConsent::STATUS_ACTIVE);
        $this->consent($pending, MarketingConsent::STATUS_PENDING);
        $this->consent($withdrawn, MarketingConsent::STATUS_WITHDRAWN);

        $response = $this->privateSession($admin)->postJson('/admin/campaigns', [
            'subject' => 'Ofertas',
            'from_name' => 'App Escritor',
            'from_email' => 'news@example.com',
            'body' => '<p>Ofertas propias</p>',
        ])->assertCreated();

        $campaignId = $response->json('campaign.id');

        $this->privateSession($admin)->getJson("/admin/campaigns/{$campaignId}")
            ->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('excluded.pending', 1)
            ->assertJsonPath('excluded.withdrawn', 1);

        $this->privateSession($admin)->getJson('/admin/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'campaigns');
    }

    public function test_pending_consent_is_not_selected_for_a_campaign(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_PENDING);

        $campaign = $this->campaign($admin);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('campaign_recipients', 0);
        $this->assertSame(Campaign::STATUS_DONE, $campaign->fresh()->status);
    }

    public function test_withdrawal_before_batch_excludes_recipient(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->actingAs($user)->postJson('/account/marketing/withdraw')->assertOk();

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk();

        Mail::assertNothingSent();

        $recipient->refresh();
        $this->assertSame(CampaignRecipient::STATE_FAILED, $recipient->state);
        $this->assertSame('consent_withdrawn', $recipient->error);
    }

    public function test_closed_account_withdraws_consent_and_is_suppressed_from_campaigns(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->privateSession($user)
            ->postJson('/account/close', ['export' => false])
            ->assertOk();

        $this->assertDatabaseHas('marketing_consents', [
            'user_id' => $user->id,
            'status' => MarketingConsent::STATUS_WITHDRAWN,
        ]);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk();

        Mail::assertNothingSent();

        $recipient->refresh();
        $this->assertSame(CampaignRecipient::STATE_FAILED, $recipient->state);
        $this->assertSame('consent_withdrawn', $recipient->error);
    }

    public function test_public_unsubscribe_requires_no_session_and_is_idempotent(): void
    {
        $user = $this->makeUser();
        $consent = $this->consent($user, MarketingConsent::STATUS_ACTIVE);

        $response = $this->getJson('/unsubscribe/'.$consent->token)
            ->assertOk()
            ->assertJson(['status' => 'unsubscribed']);

        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $consent->fresh()->status);

        $this->getJson('/unsubscribe/'.$consent->token)->assertOk();
        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $consent->fresh()->status);

        $this->getJson('/unsubscribe/token-desconocido')->assertOk();
        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $consent->fresh()->status);

        $this->getJson('/workspace')->assertStatus(401);
    }

    public function test_policy_change_does_not_reactivate_withdrawn_or_pending_contacts(): void
    {
        $withdrawnUser = $this->makeUser();
        $withdrawn = $this->consent($withdrawnUser, MarketingConsent::STATUS_WITHDRAWN);

        $pendingUser = $this->makeUser();
        $pending = $this->consent($pendingUser, MarketingConsent::STATUS_PENDING);

        config()->set('marketing.consent_text_version', '2027-01-v1');

        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $withdrawn->fresh()->status);
        $this->assertSame(MarketingConsent::STATUS_PENDING, $pending->fresh()->status);

        $this->actingAs($withdrawnUser)
            ->postJson('/account/marketing/request')
            ->assertOk()
            ->assertJson(['status' => MarketingConsent::STATUS_PENDING]);

        $withdrawn->refresh();
        $this->assertSame(MarketingConsent::STATUS_PENDING, $withdrawn->status);
        $this->assertNull($withdrawn->confirmed_at);
        $this->assertSame('2027-01-v1', $withdrawn->text_version);
    }

    public function test_password_reset_still_works_for_a_withdrawn_contact(): void
    {
        $user = $this->makeUser();
        $consent = $this->consent($user, MarketingConsent::STATUS_WITHDRAWN);

        $token = Password::createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newlongpassword12',
            'password_confirmation' => 'newlongpassword12',
        ])->assertOk()->assertJson(['status' => 'password_reset']);

        $this->assertTrue(Hash::check('newlongpassword12', $user->fresh()->password));
        $this->assertSame(MarketingConsent::STATUS_WITHDRAWN, $consent->fresh()->status);
    }

    public function test_campaign_tables_have_no_creative_segmentation_fields(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'subject', 'from_name', 'from_email', 'body', 'status', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('campaigns'),
        );

        $this->assertEqualsCanonicalizing(
            ['id', 'campaign_id', 'user_id', 'email', 'state', 'claimed_at', 'sent_at', 'error', 'created_at', 'updated_at'],
            Schema::getColumnListing('campaign_recipients'),
        );
    }

    public function test_ambiguous_transport_result_is_uncertain_and_never_automatically_resent(): void
    {
        Mail::fake();
        config()->set('marketing.campaign_batch_size', 1);

        $admin = $this->makeUser([], true);
        $ambiguousUser = $this->makeUser();
        $otherUser = $this->makeUser();
        $this->consent($ambiguousUser, MarketingConsent::STATUS_ACTIVE);
        $this->consent($otherUser, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);

        $this->app->instance(CampaignSender::class, new class extends CampaignSender
        {
            protected function deliver(Campaign $campaign, string $email): void
            {
                throw new TransportException('Respuesta perdida.');
            }
        });

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk()
            ->assertJsonPath('result.uncertain', 1)
            ->assertJsonPath('status', Campaign::STATUS_PAUSED);

        $recipient = CampaignRecipient::where('user_id', $ambiguousUser->id)->firstOrFail();
        $this->assertSame(CampaignRecipient::STATE_UNCERTAIN, $recipient->state);

        Mail::assertNothingSent();

        $this->app->instance(CampaignSender::class, new CampaignSender);
        Mail::fake();

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/resume")
            ->assertOk()
            ->assertJsonPath('result.accepted', 1)
            ->assertJsonPath('status', Campaign::STATUS_DONE);

        Mail::assertSent(CampaignMail::class, 1);
        Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo($ambiguousUser->email));
        $this->assertSame(CampaignRecipient::STATE_UNCERTAIN, $recipient->fresh()->state);
    }

    public function test_bounded_batches_resume_without_repeating_confirmed_sends(): void
    {
        Mail::fake();
        config()->set('marketing.campaign_batch_size', 1);

        $admin = $this->makeUser([], true);
        $first = $this->makeUser();
        $second = $this->makeUser();

        $this->consent($first, MarketingConsent::STATUS_ACTIVE);
        $this->consent($second, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk()
            ->assertJsonPath('result.accepted', 1)
            ->assertJsonPath('status', Campaign::STATUS_PAUSED);

        Mail::assertSent(CampaignMail::class, 1);
        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            return count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === [];
        });
        $this->assertSame(1, CampaignRecipient::where('state', CampaignRecipient::STATE_ACCEPTED)->count());
        $this->assertSame(1, CampaignRecipient::where('state', CampaignRecipient::STATE_PENDING)->count());

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/resume")
            ->assertOk()
            ->assertJsonPath('result.accepted', 1)
            ->assertJsonPath('status', Campaign::STATUS_DONE);

        Mail::assertSent(CampaignMail::class, 2);
        $this->assertSame(2, CampaignRecipient::where('state', CampaignRecipient::STATE_ACCEPTED)->count());
        $this->assertSame(0, CampaignRecipient::where('state', CampaignRecipient::STATE_PENDING)->count());
    }

    public function test_campaign_email_includes_the_recipient_unsubscribe_link(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $consent = $this->consent($user, MarketingConsent::STATUS_ACTIVE);
        $campaign = $this->campaign($admin);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk()
            ->assertJsonPath('result.accepted', 1);

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) use ($consent): bool {
            $header = $mail->headers()->text['List-Unsubscribe'] ?? '';

            return str_contains($mail->render(), '/unsubscribe/'.$consent->token)
                && str_contains($header, '/unsubscribe/'.$consent->token);
        });
    }

    public function test_admin_campaign_start_requires_admin_and_recent_reauth(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $campaign = $this->campaign($admin);

        $this->privateSession($admin, false)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertStatus(423);

        $nonAdmin = $this->makeUser();

        $this->privateSession($nonAdmin)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertStatus(403);

        $this->privateSession($admin, true)
            ->postJson("/admin/campaigns/{$campaign->id}/start")
            ->assertOk();
    }

    public function test_overlapping_batches_claim_recipients_before_transport(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);
        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $sender = new class extends CampaignSender
        {
            private bool $reentered = false;

            protected function deliver(Campaign $campaign, string $email): void
            {
                parent::deliver($campaign, $email);

                if (! $this->reentered) {
                    $this->reentered = true;
                    $this->sendBatch($campaign);
                }
            }
        };

        $sender->sendBatch($campaign);

        Mail::assertSent(CampaignMail::class, 1);
        $this->assertSame(0, CampaignRecipient::where('state', CampaignRecipient::STATE_PENDING)->count());
        $this->assertSame(0, CampaignRecipient::where('state', CampaignRecipient::STATE_SENDING)->count());
        $this->assertSame(1, CampaignRecipient::where('state', CampaignRecipient::STATE_ACCEPTED)->count());
    }

    public function test_interrupted_campaign_claim_is_reviewable_and_reconcilable(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);

        $campaign = $this->campaign($admin);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'state' => CampaignRecipient::STATE_SENDING,
        ]);
        $campaign->forceFill(['status' => Campaign::STATUS_PAUSED])->save();

        $this->privateSession($admin)
            ->getJson("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('states.sending', 1)
            ->assertJsonPath('recipients.0.state', CampaignRecipient::STATE_SENDING);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/resume")
            ->assertOk()
            ->assertJsonPath('result.accepted', 0);
        Mail::assertNothingSent();

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile", [
                'state' => CampaignRecipient::STATE_ACCEPTED,
            ])
            ->assertOk()
            ->assertJsonPath('status', Campaign::STATUS_DONE);

        $this->assertSame(CampaignRecipient::STATE_ACCEPTED, $recipient->fresh()->state);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $admin->id,
            'action' => 'campaign_recipient_reconciled',
            'subject_id' => $recipient->id,
        ]);
    }

    public function test_pending_or_already_sent_recipients_cannot_be_reconciled(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $campaign = $this->campaign($admin);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'pendiente@example.com',
            'state' => CampaignRecipient::STATE_PENDING,
        ]);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile", [
                'state' => CampaignRecipient::STATE_ACCEPTED,
            ])
            ->assertStatus(409);

        $nonAdmin = $this->makeUser();
        $recipient->forceFill(['state' => CampaignRecipient::STATE_SENDING])->save();

        $this->privateSession($nonAdmin)
            ->postJson("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile", [
                'state' => CampaignRecipient::STATE_ACCEPTED,
            ])
            ->assertStatus(403);
    }

    public function test_live_smtp_claim_is_not_reconciled_by_an_operator(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);
        $campaign = $this->campaign($admin);
        $campaign->forceFill(['status' => Campaign::STATUS_RUNNING])->save();

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'state' => CampaignRecipient::STATE_SENDING,
            'claimed_at' => now(),
        ]);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile", [
                'state' => CampaignRecipient::STATE_ACCEPTED,
            ])
            ->assertStatus(409);

        $this->assertSame(CampaignRecipient::STATE_SENDING, $recipient->fresh()->state);
        $this->assertSame(Campaign::STATUS_RUNNING, $campaign->fresh()->status);
        $this->assertDatabaseMissing('admin_audit_events', [
            'action' => 'campaign_recipient_reconciled',
            'subject_id' => $recipient->id,
        ]);
        Mail::assertNothingSent();
    }

    public function test_interrupted_claim_reconciliation_wins_over_a_late_sender_completion(): void
    {
        Mail::fake();

        $admin = $this->makeUser([], true);
        $user = $this->makeUser();
        $this->consent($user, MarketingConsent::STATUS_ACTIVE);
        $campaign = $this->campaign($admin);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'state' => CampaignRecipient::STATE_SENDING,
            'claimed_at' => now()->subHour(),
        ]);

        $this->privateSession($admin)
            ->postJson("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/reconcile", [
                'state' => CampaignRecipient::STATE_FAILED,
            ])
            ->assertOk()
            ->assertJsonPath('recipient.state', CampaignRecipient::STATE_FAILED);

        $lateCompletion = app(CampaignSender::class)
            ->completeClaim($recipient->fresh(), CampaignRecipient::STATE_ACCEPTED);

        $this->assertFalse($lateCompletion);
        $this->assertSame(CampaignRecipient::STATE_FAILED, $recipient->fresh()->state);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $admin->id,
            'action' => 'campaign_recipient_reconciled',
            'subject_id' => $recipient->id,
        ]);
        Mail::assertNothingSent();
    }
}
