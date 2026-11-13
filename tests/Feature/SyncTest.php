<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\Device;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\SyncConflict;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_idempotent_operation_replays_one_stored_result_and_applies_once(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);
        $device = $this->authorizeDevice($user, [$novel->id]);

        $key = (string) Str::uuid();
        $payload = [
            'device_id' => $device,
            'idempotency_key' => $key,
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('Segunda versión')],
        ];

        $first = $this->sync($user, $payload)
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('revision', 2);

        $second = $this->sync($user, $payload)
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('revision', 2);

        $this->assertEquals($first->json(), $second->json());
        $this->assertDatabaseCount('sync_operations', 1);
        $this->assertSame(2, $scene->fresh()->revision);
        $this->assertSame('Segunda versión', $this->text($scene->fresh()));

        $this->sync($user, array_merge($payload, [
            'payload' => ['content' => $this->content('Otra cosa')],
        ]))->assertStatus(409)
            ->assertJsonPath('status', 'idempotency_key_reused');

        $this->assertSame('Segunda versión', $this->text($scene->fresh()));
    }

    public function test_idempotency_keys_are_scoped_per_user_and_device(): void
    {
        $key = (string) Str::uuid();

        foreach ([$this->writer(), $this->writer()] as $user) {
            $novel = $this->novelFor($user);
            $scene = $this->sceneFor($novel);
            $device = $this->authorizeDevice($user, [$novel->id]);

            $this->sync($user, [
                'device_id' => $device,
                'idempotency_key' => $key,
                'element_type' => 'scene',
                'element_id' => $scene->id,
                'action' => 'update_content',
                'base_revision' => 1,
                'payload' => ['content' => $this->content('Cambio '.$user->id)],
            ])->assertOk()->assertJsonPath('status', 'applied');
        }

        $this->assertDatabaseCount('sync_operations', 2);
    }

    public function test_owner_sync_respects_a_collaborator_chapter_lease(): void
    {
        $owner = $this->writer();
        $collaborator = $this->writer();
        $novel = $this->novelFor($owner);
        $scene = $this->sceneFor($novel, 'Base');
        $device = $this->authorizeDevice($owner, [$novel->id]);
        $chapter = Chapter::findOrFail(
            ChapterAlternative::whereKey($scene->chapter_alternative_id)->value('chapter_id'),
        );

        CreativePermission::create([
            'user_id' => $collaborator->id,
            'resource_type' => 'chapter',
            'resource_id' => $chapter->id,
            'scope' => 'edit',
            'granted_by' => $owner->id,
        ]);
        EditLease::create([
            'resource_type' => 'chapter',
            'resource_id' => $chapter->id,
            'user_id' => $collaborator->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->sync($owner, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('Cambio del propietario')],
        ])->assertStatus(423);

        $this->assertSame('Base', $this->text($scene->fresh()));
    }

    public function test_same_element_conflict_preserves_both_sides_and_resolves_only_with_current_revision(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'Base común');
        $device = $this->authorizeDevice($user, [$novel->id]);

        $scene->forceFill([
            'content' => $this->content('Cambio del servidor'),
            'revision' => 2,
        ])->save();

        $key = (string) Str::uuid();
        $payload = [
            'device_id' => $device,
            'idempotency_key' => $key,
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('Cambio local')],
        ];

        $response = $this->sync($user, $payload)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('conflict.status', 'pending');

        $conflictId = $response->json('conflict.id');
        $conflict = SyncConflict::findOrFail($conflictId);

        $this->assertSame(2, $conflict->server_revision);
        $this->assertSame(1, $conflict->client_revision);
        $this->assertSame('Cambio del servidor', $this->text(['blocks' => $conflict->server_content['blocks']]));
        $this->assertSame('Cambio local', $this->text(['blocks' => $conflict->client_content['blocks']]));
        $this->assertSame('Cambio del servidor', $this->text($scene->fresh()));
        $this->assertSame(2, $scene->fresh()->revision);

        $retry = $this->sync($user, $payload)
            ->assertStatus(409)
            ->assertJsonPath('conflict.id', $conflictId);

        $this->assertEquals($response->json(), $retry->json());
        $this->assertDatabaseCount('sync_conflicts', 1);

        $this->asWriter($user)
            ->postJson("/sync/conflicts/{$conflictId}/resolve", [
                'resolution' => 'client',
                'base_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'stale_revision');

        $this->asWriter($user)
            ->postJson("/sync/conflicts/{$conflictId}/resolve", [
                'resolution' => 'client',
                'base_revision' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('conflict.status', 'resolved_client')
            ->assertJsonPath('resource.revision', 3);

        $conflict->refresh();
        $this->assertSame('resolved_client', $conflict->status);
        $this->assertSame('Cambio local', $this->text($scene->fresh()));
        $this->assertSame(3, $scene->fresh()->revision);
        $this->assertSame('Cambio del servidor', $this->text(['blocks' => $conflict->server_content['blocks']]));
        $this->assertSame('Cambio local', $this->text(['blocks' => $conflict->client_content['blocks']]));
    }

    public function test_stale_trash_is_rejected_instead_of_hiding_a_newer_revision(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);
        $device = $this->authorizeDevice($user, [$novel->id]);

        $scene->forceFill(['revision' => 2, 'content' => $this->content('Nueva versión')])->save();

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'trash',
            'base_revision' => 1,
            'payload' => [],
        ])->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('reason', 'revision_conflict');

        $this->assertDatabaseMissing('sync_trash', ['resource_type' => 'scene', 'resource_id' => $scene->id]);
        $this->assertSame('Nueva versión', $this->text($scene->fresh()));
    }

    public function test_server_trash_and_local_edit_keeps_local_text_pending_without_resurrection(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'Texto que no debe perderse');
        $device = $this->authorizeDevice($user, [$novel->id]);

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'trash',
            'base_revision' => 1,
            'payload' => [],
        ])->assertOk()->assertJsonPath('trashed', true);

        $this->assertDatabaseHas('sync_trash', [
            'resource_type' => 'scene',
            'resource_id' => $scene->id,
        ]);

        $response = $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('Edición local sin conexión')],
        ])->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('reason', 'resource_trashed')
            ->assertJsonPath('resurrected', false)
            ->assertJsonPath('conflict.status', 'trashed_pending');

        $this->assertSame('Edición local sin conexión', $this->text([
            'blocks' => $response->json('conflict.client_content.blocks'),
        ]));

        $this->assertSame('Texto que no debe perderse', $this->text($scene->fresh()));
        $this->assertSame(1, $scene->fresh()->revision);
        $this->assertDatabaseHas('sync_trash', [
            'resource_type' => 'scene',
            'resource_id' => $scene->id,
        ]);
        $this->assertDatabaseCount('scenes', 1);
    }

    public function test_stale_chapter_and_card_trash_are_rejected(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $device = $this->authorizeDevice($user, [$novel->id]);
        $chapter = Chapter::where('novel_id', $novel->id)->firstOrFail();
        $chapter->forceFill(['revision' => 2])->save();
        $card = Card::create(['universe_id' => $novel->universe_id, 'area' => 'characters', 'name' => 'Elena']);
        $card->forceFill(['revision' => 2])->save();

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'chapter',
            'element_id' => $chapter->id,
            'action' => 'trash',
            'base_revision' => 1,
            'payload' => [],
        ])->assertStatus(409)->assertJsonPath('reason', 'revision_conflict');

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'card',
            'element_id' => $card->id,
            'action' => 'trash',
            'base_revision' => 1,
            'payload' => [],
        ])->assertStatus(409)->assertJsonPath('reason', 'revision_conflict');

        $this->assertDatabaseMissing('sync_trash', ['resource_type' => 'chapter', 'resource_id' => $chapter->id]);
        $this->assertDatabaseMissing('sync_trash', ['resource_type' => 'card', 'resource_id' => $card->id]);
    }

    public function test_competing_order_changes_create_structure_conflict_without_losing_chapters(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user, ['Uno', 'Dos', 'Tres']);
        $chapters = $novel->chapters()->orderBy('position')->get();
        $device = $this->authorizeDevice($user, [$novel->id]);

        $firstOrder = [$chapters[1]->id, $chapters[0]->id, $chapters[2]->id];

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'novel',
            'element_id' => $novel->id,
            'action' => 'reorder',
            'base_revision' => 1,
            'payload' => ['order' => $firstOrder],
        ])->assertOk()->assertJsonPath('revision', 2);

        $secondOrder = [$chapters[2]->id, $chapters[1]->id, $chapters[0]->id];

        $response = $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'novel',
            'element_id' => $novel->id,
            'action' => 'reorder',
            'base_revision' => 1,
            'payload' => ['order' => $secondOrder],
        ])->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('reason', 'structure_conflict');

        $this->assertSame($firstOrder, $response->json('conflict.server_content.order'));
        $this->assertSame($secondOrder, $response->json('conflict.client_content.order'));
        $this->assertDatabaseCount('chapters', 3);

        $actual = $novel->chapters()->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame($firstOrder, $actual);
        $this->assertSame([], array_values(array_diff($actual, $chapters->pluck('id')->all())));
    }

    public function test_missing_resources_are_reported_without_dangling_conflicts(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);
        $device = $this->authorizeDevice($user, [$novel->id]);

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => 999999,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('Huérfano')],
        ])->assertStatus(422)
            ->assertJsonPath('status', 'missing_resource')
            ->assertJsonPath('missing_resources.0.type', 'scene')
            ->assertJsonPath('missing_resources.0.id', 999999);

        $content = $this->content('Con referencia rota');
        $content['blocks'][0]['references'] = [999999];

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $content],
        ])->assertStatus(422)
            ->assertJsonPath('status', 'missing_resource')
            ->assertJsonPath('missing_resources.0.type', 'card')
            ->assertJsonPath('missing_resources.0.id', 999999);

        $this->assertDatabaseCount('sync_conflicts', 0);
        $this->assertSame('Escena original', $this->text($scene->fresh()));
    }

    public function test_device_authorization_requires_a_session_and_returns_only_selected_manifests_without_totp_secret(): void
    {
        $this->postJson('/sync/devices', [
            'name' => 'Mac',
            'projects' => [1],
        ])->assertUnauthorized();

        $user = $this->writer();
        $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $first = $this->novelFor($user);
        $second = $this->novelFor($user);

        $response = $this->asWriter($user)
            ->postJson('/sync/devices', [
                'name' => 'Mac',
                'projects' => [$first->id],
            ])->assertCreated()
            ->assertJsonCount(1, 'device.projects')
            ->assertJsonPath('device.projects.0.novel_id', $first->id);

        $body = $response->getContent();
        $this->assertStringNotContainsString('totp_secret', $body);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $body);

        $deviceId = $response->json('device.id');

        $read = $this->asWriter($user)
            ->getJson("/sync/devices/{$deviceId}")
            ->assertOk()
            ->assertJsonCount(1, 'device.projects')
            ->assertJsonPath('device.projects.0.novel_id', $first->id);

        $this->assertSame(1, Device::findOrFail($deviceId)->projects()->count());
        $this->assertNotSame($first->id, $second->id);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $read->getContent());
    }

    public function test_revoked_device_is_rejected_on_next_sync_and_read(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);
        $device = $this->authorizeDevice($user, [$novel->id]);

        $this->asWriter($user)
            ->deleteJson("/sync/devices/{$device}")
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->assertNotNull(Device::findOrFail($device)->revoked_at);

        $this->sync($user, [
            'device_id' => $device,
            'idempotency_key' => (string) Str::uuid(),
            'element_type' => 'scene',
            'element_id' => $scene->id,
            'action' => 'update_content',
            'base_revision' => 1,
            'payload' => ['content' => $this->content('No debe aplicarse')],
        ])->assertStatus(403)->assertJsonPath('status', 'device_revoked');

        $this->asWriter($user)
            ->getJson("/sync/devices/{$device}")
            ->assertStatus(403)
            ->assertJsonPath('status', 'device_revoked');

        $this->assertSame('Escena original', $this->text($scene->fresh()));
        $this->assertSame(1, $scene->fresh()->revision);
    }

    public function test_desktop_contract_describes_local_lock_recovery_and_no_server_totp_secret(): void
    {
        $this->getJson('/sync/desktop-contract')
            ->assertOk()
            ->assertJsonPath('local_data.local_unlock_password_separate_from_account', true)
            ->assertJsonPath('local_data.server_totp_secret_distributed_to_device', false)
            ->assertJsonPath('local_data.local_unlock_creates_server_session', false)
            ->assertJsonPath('lock.on_app_close', true)
            ->assertJsonPath('lock.on_system_lock', true)
            ->assertJsonPath('lock.idle_timeout_minutes', 15)
            ->assertJsonPath('recovery.online_redownload_after_forgotten_local_password', true)
            ->assertJsonPath('recovery.local_changes_with_lost_key_may_be_irrecoverable', true)
            ->assertJsonPath('projects.only_selected_authorized_manifests', true)
            ->assertJsonPath('conflicts.automatic_resurrection', false)
            ->assertJsonPath('exports.docx_epub_server_queued_until_online', true);

        $this->getJson('/sync/totp-secret')->assertNotFound();
    }

    private function novelFor(User $user, array $chapterTitles = ['Uno']): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);

        foreach ($chapterTitles as $position => $title) {
            $chapter = Chapter::create([
                'novel_id' => $novel->id,
                'title' => $title,
                'position' => $position,
            ]);

            ChapterAlternative::create([
                'chapter_id' => $chapter->id,
                'alias' => 'Principal',
                'is_active' => true,
            ]);
        }

        return $novel->refresh();
    }

    private function sceneFor(Novel $novel, string $text = 'Escena original'): Scene
    {
        $alternative = ChapterAlternative::where('chapter_id', $novel->chapters()->firstOrFail()->id)
            ->firstOrFail();

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'position' => 0,
            'content' => $this->content($text),
        ]);
    }

    private function authorizeDevice(User $user, array $novelIds): int
    {
        return $this->asWriter($user)
            ->postJson('/sync/devices', [
                'name' => 'Mac',
                'projects' => $novelIds,
            ])->assertCreated()
            ->json('device.id');
    }

    private function sync(User $user, array $payload): TestResponse
    {
        return $this->asWriter($user)->postJson('/sync/operations', $payload);
    }

    private function content(string $text): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
        ];
    }

    private function text(Scene|array $sceneOrContent): string
    {
        $content = $sceneOrContent instanceof Scene ? $sceneOrContent->content : $sceneOrContent;

        return collect($content['blocks'] ?? [])->pluck('text')->implode("\n");
    }
}
