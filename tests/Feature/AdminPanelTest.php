<?php

namespace Tests\Feature;

use App\Models\AccountQuota;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_writers_cannot_open_the_admin_shell(): void
    {
        $this->asWriter($this->writer())->get('/admin')->assertForbidden();
    }

    public function test_admin_json_contract_is_unchanged(): void
    {
        $admin = $this->admin();

        $this->asAdmin($admin)->getJson('/admin')->assertOk()->assertExactJson(['area' => 'platform-admin']);
        $this->asAdmin($admin)->getJson('/admin/accounts')->assertOk()->assertJsonStructure(['accounts']);
        $this->asAdmin($admin)->getJson('/admin/audit')->assertOk()->assertJsonStructure(['events']);
    }

    public function test_account_and_communications_permissions_are_separate(): void
    {
        $accountsOnly = $this->admin(['admin_accounts' => true, 'admin_communications' => false]);
        $communicationsOnly = $this->admin(['admin_accounts' => false, 'admin_communications' => true]);

        $this->asAdmin($accountsOnly)->get('/admin/accounts')->assertOk();

        $this->asAdmin($accountsOnly)->postJson('/admin/campaigns', [
            'subject' => 'Novedades',
            'from_name' => 'App',
            'from_email' => 'news@example.com',
            'body' => '<p>Novedades</p>',
        ])->assertForbidden();

        $this->asAdmin($communicationsOnly)->get('/admin/accounts')->assertForbidden();
        $this->asAdmin($communicationsOnly)->get('/admin')->assertForbidden();

        $this->asAdmin($communicationsOnly)->postJson('/admin/campaigns', [
            'subject' => 'Novedades',
            'from_name' => 'App',
            'from_email' => 'news@example.com',
            'body' => '<p>Novedades</p>',
        ])->assertCreated();
    }

    public function test_admin_shell_shows_only_account_data_and_no_creative_titles(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();
        $universe = Universe::create(['owner_id' => $writer->id, 'name' => 'Arven']);
        Novel::create(['universe_id' => $universe->id, 'owner_id' => $writer->id, 'title' => 'Manuscrito privado']);
        AccountQuota::create(['user_id' => $writer->id, 'bytes_limit' => 1048576]);

        $this->asAdmin($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Administración')
            ->assertSee('Cuentas')
            ->assertSee('Auditoría')
            ->assertDontSee($writer->fresh()->totp_secret)
            ->assertDontSee('Manuscrito privado');

        $this->asAdmin($admin)
            ->get('/admin/accounts')
            ->assertOk()
            ->assertSee($writer->email)
            ->assertSee('1048576')
            ->assertDontSee($writer->fresh()->totp_secret)
            ->assertDontSee('Manuscrito privado')
            ->assertDontSee('/admin/accounts/'.$admin->id.'/suspend', false)
            ->assertSee('/admin/accounts/'.$writer->id.'/suspend', false);
    }

    public function test_quota_change_from_the_shell_updates_and_audits(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();

        $this->asAdmin($admin)
            ->post("/admin/accounts/{$writer->id}/quota", [
                '_method' => 'PATCH',
                'bytes_limit' => 2097152,
            ])
            ->assertRedirect('/admin/accounts');

        $this->assertDatabaseHas('account_quotas', ['user_id' => $writer->id, 'bytes_limit' => 2097152]);
        $this->assertDatabaseHas('admin_audit_events', ['actor_id' => $admin->id, 'action' => 'account.quota', 'subject_id' => $writer->id]);

        $this->asAdmin($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertSee('Cuota modificada')
            ->assertSee($writer->email);
    }

    public function test_suspension_and_restore_from_the_shell_audit_and_redirect(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();

        $this->asAdmin($admin)
            ->post("/admin/accounts/{$writer->id}/suspend", ['_method' => 'PATCH'])
            ->assertRedirect('/admin/accounts');

        $this->assertNotNull($writer->fresh()->suspended_at);

        $this->asAdmin($admin)
            ->post("/admin/accounts/{$writer->id}/restore", ['_method' => 'PATCH'])
            ->assertRedirect('/admin/accounts');

        $this->assertNull($writer->fresh()->suspended_at);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'account.suspend', 'subject_id' => $writer->id]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'account.restore', 'subject_id' => $writer->id]);
    }
}
