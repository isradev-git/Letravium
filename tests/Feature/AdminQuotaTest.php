<?php

namespace Tests\Feature;

use App\Models\AccountQuota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class AdminQuotaTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_registration_creates_free_configurable_quota(): void
    {
        $this->postJson('/register', [
            'name' => 'Elena',
            'email' => 'quota@example.com',
            'password' => 'longpassword12',
            'password_confirmation' => 'longpassword12',
        ])->assertCreated();

        $user = User::where('email', 'quota@example.com')->firstOrFail();

        $this->assertDatabaseHas('account_quotas', [
            'user_id' => $user->id,
            'bytes_limit' => AccountQuota::DEFAULT_BYTES,
        ]);
    }

    public function test_admin_updates_quota_and_audits_without_creative_access(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();

        $this->asAdmin($admin)
            ->patchJson("/admin/accounts/{$writer->id}/quota", ['bytes_limit' => 1048576])
            ->assertOk()
            ->assertJsonPath('quota.bytes_limit', 1048576);

        $this->assertDatabaseHas('account_quotas', [
            'user_id' => $writer->id,
            'bytes_limit' => 1048576,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $admin->id,
            'action' => 'account.quota',
            'subject_id' => $writer->id,
        ]);

        $this->asAdmin($admin)->getJson('/workspace')->assertStatus(403);
    }

    public function test_suspension_blocks_private_access_until_restored(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();

        $this->asAdmin($admin)
            ->patchJson("/admin/accounts/{$writer->id}/suspend")
            ->assertOk();

        $this->asWriter($writer->fresh())->getJson('/workspace')->assertStatus(403);

        $this->asAdmin($admin)
            ->patchJson("/admin/accounts/{$writer->id}/restore")
            ->assertOk();

        $this->asWriter($writer->fresh())->getJson('/workspace')->assertOk();
    }
}
