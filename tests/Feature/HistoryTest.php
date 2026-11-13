<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\TextReference;
use App\Models\Universe;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function document(array $texts): array
    {
        $blocks = [];

        foreach (array_values($texts) as $index => $text) {
            $blocks[] = ['id' => 'b'.($index + 1), 'type' => 'paragraph', 'text' => $text];
        }

        return ['schema_version' => 1, 'blocks' => $blocks];
    }

    private function sceneFor($user, array $texts = ['El puente de piedra.']): array
    {
        $novel = $this->novelFor($user);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'position' => 0,
            'content' => $this->document($texts),
        ]);

        return [$novel, $chapter, $alternative, $scene];
    }

    public function test_revision_list_is_cursor_paginated(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user);

        foreach (['Uno', 'Dos', 'Tres'] as $text) {
            $scene->refresh();
            $this->asWriter($user)
                ->putJson("/workspace/scenes/{$scene->id}/content", [
                    'base_revision' => $scene->revision,
                    'content' => $this->document([$text]),
                ])->assertOk();
        }

        $first = $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene->id}/revisions?limit=1")
            ->assertOk()
            ->assertJsonCount(1, 'revisions')
            ->assertJsonPath('has_more', true);

        $this->assertNotNull($first->json('next_cursor'));

        $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene->id}/revisions?limit=10")
            ->assertOk()
            ->assertJsonPath('has_more', false);
    }

    public function test_stale_scene_save_returns_conflict_and_never_overwrites(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'operation_id' => 'op-1',
                'content' => $this->document(['Primera guardada.']),
            ])
            ->assertOk()
            ->assertJsonPath('scene.revision', 2);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'operation_id' => 'op-1',
                'content' => $this->document(['Texto obsoleto que no debe pisar.']),
            ])
            ->assertStatus(409)
            ->assertJson([
                'conflict' => true,
                'base_revision' => 1,
                'current_revision' => 2,
            ]);

        $this->assertSame('Primera guardada.', $scene->refresh()->content['blocks'][0]['text']);
        $this->assertSame(2, (int) $scene->revision);
    }

    public function test_chapter_content_save_rejects_a_stale_revision(): void
    {
        $user = $this->writer();
        [, $chapter, , $scene] = $this->sceneFor($user, ['Uno']);

        $this->asWriter($user)
            ->putJson("/workspace/chapters/{$chapter->id}/content", [
                'base_revision' => 1,
                'scenes' => [[
                    'id' => $scene->id,
                    'base_revision' => 1,
                    'content' => $this->document(['Capítulo guardado.']),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('chapter.revision', 2);

        $this->asWriter($user)
            ->putJson("/workspace/chapters/{$chapter->id}/content", [
                'base_revision' => 1,
                'scenes' => [[
                    'id' => $scene->id,
                    'base_revision' => 1,
                    'content' => $this->document(['No debe aplicarse.']),
                ]],
            ])
            ->assertStatus(409);

        $this->assertSame('Capítulo guardado.', $scene->refresh()->content['blocks'][0]['text']);
    }

    public function test_pending_edit_survives_session_loss_and_is_never_auto_confirmed(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user);

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/pending-edits", [
                'client_id' => 'browser-a',
                'base_revision' => 1,
                'content' => $this->document(['Pendiente local.']),
            ])
            ->assertCreated()
            ->assertJsonPath('pending_edit.status', 'pending');

        $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene->id}/pending-edits")
            ->assertOk()
            ->assertJsonCount(1, 'pending_edits')
            ->assertJsonPath('pending_edits.0.status', 'pending')
            ->assertJsonPath('pending_edits.0.client_id', 'browser-a');

        $this->assertSame('El puente de piedra.', $scene->refresh()->content['blocks'][0]['text']);

        $this->asWriter($this->writer())
            ->getJson("/workspace/scenes/{$scene->id}/pending-edits")
            ->assertNotFound();
    }

    public function test_history_points_are_attributable_and_immutable(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user, ['Uno']);

        $this->asWriter($user)->putJson("/workspace/scenes/{$scene->id}/content", [
            'base_revision' => 1,
            'content' => $this->document(['Dos.']),
        ])->assertOk();

        $this->asWriter($user)->putJson("/workspace/scenes/{$scene->id}/content", [
            'base_revision' => 2,
            'content' => $this->document(['Tres.']),
        ])->assertOk();

        $rows = DB::table('content_revisions')->where('scene_id', $scene->id)->orderBy('revision')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($user->id, (int) $rows[0]->author_id);
        $first = $rows[0];

        $this->asWriter($user)->putJson("/workspace/scenes/{$scene->id}/content", [
            'base_revision' => 3,
            'content' => $this->document(['Cuatro.']),
        ])->assertOk();

        $again = DB::table('content_revisions')->where('id', $first->id)->first();
        $this->assertSame($first->content, $again->content);
        $this->assertSame($first->created_at, $again->created_at);
        $this->assertSame($first->revision, $again->revision);
        $this->assertCount(3, DB::table('content_revisions')->where('scene_id', $scene->id)->get());
    }

    public function test_automatic_history_policy_waits_five_minutes_of_changed_work(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user, ['Uno']);
        $service = app(HistoryService::class);

        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->assertTrue($service->recordAutomatic($scene, $user->id));
        $this->assertFalse($service->recordAutomatic($scene, $user->id));

        $service->saveScene($scene->refresh(), $this->document(['Dos.']), 1, $user->id);

        Carbon::setTestNow('2026-01-01 10:06:00');
        $this->assertTrue($service->recordAutomatic($scene->refresh(), $user->id));
        $this->assertSame(
            2,
            DB::table('content_revisions')->where('scene_id', $scene->id)->where('reason', 'automatic')->count(),
        );

        Carbon::setTestNow();
    }

    public function test_split_scene_preserves_all_blocks_in_order_and_previous_state(): void
    {
        $user = $this->writer();
        [, , $alternative, $scene] = $this->sceneFor($user, ['A', 'B', 'C', 'D']);

        $result = $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/split", [
                'base_revision' => 1,
                'block_index' => 2,
                'title' => 'Segunda mitad',
            ])
            ->assertOk()
            ->json();

        $this->assertSame(['A', 'B'], array_column($result['scene']['content']['blocks'], 'text'));
        $this->assertSame(['C', 'D'], array_column($result['created_scene']['content']['blocks'], 'text'));
        $this->assertSame(2, (int) $result['scene']['revision']);
        $this->assertSame($scene->position + 1, (int) $result['created_scene']['position']);

        $this->assertSame(
            ['A', 'B', 'C', 'D'],
            array_column(json_decode(DB::table('content_revisions')
                ->where('scene_id', $scene->id)->where('revision', 1)->value('content'), true)['blocks'], 'text'),
        );

        $countBefore = $alternative->scenes()->count();

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/split", [
                'base_revision' => 1,
                'block_index' => 1,
            ])
            ->assertStatus(409);

        $this->assertSame($countBefore, $alternative->scenes()->count());

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/split", [
                'base_revision' => 2,
                'block_index' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_merge_adjacent_scenes_preserves_content_order_and_links(): void
    {
        $user = $this->writer();
        [$novel, , $alternative, $first] = $this->sceneFor($user, ['A', 'B']);
        $second = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'position' => 1,
            'content' => $this->document(['C', 'D']),
        ]);

        $plot = Plot::create(['novel_id' => $novel->id, 'name' => 'La carta']);
        DB::table('plot_scene')->insert(['plot_id' => $plot->id, 'scene_id' => $second->id]);

        $card = Card::create([
            'universe_id' => $novel->universe_id,
            'area' => 'characters',
            'name' => 'Elena',
        ]);
        TextReference::create([
            'scene_id' => $second->id,
            'card_id' => $card->id,
            'created_by' => $user->id,
        ]);

        $merged = $this->asWriter($user)
            ->postJson("/workspace/scenes/{$first->id}/merge", ['base_revision' => 1])
            ->assertOk()
            ->json('scene');

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($merged['content']['blocks'], 'text'));
        $this->assertSame(2, (int) $merged['revision']);
        $this->assertNull(Scene::find($second->id));

        $this->assertDatabaseHas('plot_scene', ['plot_id' => $plot->id, 'scene_id' => $first->id]);
        $this->assertDatabaseHas('text_references', ['scene_id' => $first->id, 'card_id' => $card->id]);

        $previous = DB::table('content_revisions')->where('reason', 'merge')->get()
            ->map(fn ($row) => json_decode($row->content, true)['blocks'][0]['text'])
            ->sort()->values()->all();
        $this->assertSame(['A', 'C'], $previous);
    }

    public function test_merging_with_a_stale_revision_is_rejected(): void
    {
        $user = $this->writer();
        [, , $alternative, $first] = $this->sceneFor($user, ['A']);
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'position' => 1,
            'content' => $this->document(['B']),
        ]);

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$first->id}/merge", ['base_revision' => 9])
            ->assertStatus(409);

        $this->assertSame(2, $alternative->scenes()->count());
    }

    public function test_removing_a_populated_card_field_warns_and_keeps_old_data(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $card = Card::create([
            'universe_id' => $universe->id,
            'area' => 'characters',
            'name' => 'Elena',
            'fields' => ['reino' => 'Valdara'],
        ]);

        $field = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/card-fields", [
                'key' => 'reino',
                'type' => 'text',
                'label' => 'Reino',
            ])
            ->assertCreated()
            ->json('field');

        $this->asWriter($user)
            ->deleteJson("/workspace/card-fields/{$field['id']}")
            ->assertStatus(409)
            ->assertJsonPath('cards_affected', 1);

        $this->assertNull(DB::table('card_field_definitions')->where('id', $field['id'])->value('removed_at'));

        $this->asWriter($user)
            ->deleteJson("/workspace/card-fields/{$field['id']}?confirm=1")
            ->assertOk();

        $removed = DB::table('card_field_definitions')->where('id', $field['id'])->first();
        $this->assertNotNull($removed->removed_at);
        $this->assertSame(['Valdara'], array_values(json_decode($removed->removed_values, true)));
        $this->assertSame('Valdara', $card->refresh()->fields['reino']);
    }

    public function test_trash_entry_past_thirty_days_is_still_restorable_and_not_purged(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->postJson("/workspace/novel/{$novel->id}/trash")
            ->assertCreated();

        $entry = DB::table('trash_entries')->where('resource_type', 'novel')->where('resource_id', $novel->id)->first();
        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(
            29,
            Carbon::parse($entry->deleted_at)->diffInDays(Carbon::parse($entry->restore_deadline), true),
        );

        DB::table('trash_entries')->where('id', $entry->id)->update([
            'deleted_at' => now()->subDays(31),
            'restore_deadline' => now()->subDay(),
        ]);

        $this->asWriter($user)
            ->getJson('/workspace/trash')
            ->assertOk()
            ->assertJsonPath('trash.0.expired', true);

        $this->assertNotNull(Novel::withoutGlobalScope('trashed')->find($novel->id));

        $this->asWriter($user)
            ->postJson("/workspace/trash/{$entry->id}/restore")
            ->assertOk();

        $this->assertDatabaseMissing('trash_entries', ['id' => $entry->id]);
        $this->assertNotNull(Novel::find($novel->id));
    }

    public function test_trashed_chapter_is_hidden_from_current_work_and_returns_on_restore(): void
    {
        $user = $this->writer();
        [$novel, $chapter, , $scene] = $this->sceneFor($user, ['Texto visible en la escena.']);

        $this->asWriter($user)
            ->postJson("/workspace/chapter/{$chapter->id}/trash")
            ->assertCreated();

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/chapters")
            ->assertOk()
            ->assertJsonCount(0, 'chapters');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertDontSee('Texto visible en la escena.');

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/search?q=visible")
            ->assertOk()
            ->assertJsonPath('count', 0);

        $entry = DB::table('trash_entries')
            ->where('resource_type', 'chapter')
            ->where('resource_id', $chapter->id)
            ->firstOrFail();

        $this->asWriter($user)
            ->postJson("/workspace/trash/{$entry->id}/restore")
            ->assertOk();

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/chapters")
            ->assertOk()
            ->assertJsonCount(1, 'chapters')
            ->assertJsonPath('chapters.0.title', 'Uno');

        $this->assertSame('Texto visible en la escena.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_trashed_card_is_hidden_from_its_universe_and_returns_on_restore(): void
    {
        $user = $this->writer();
        [$novel] = $this->sceneFor($user);
        $universe = Universe::findOrFail($novel->universe_id);
        $card = Card::create([
            'universe_id' => $universe->id,
            'area' => 'characters',
            'name' => 'Elena',
        ]);

        $this->asWriter($user)
            ->postJson("/workspace/card/{$card->id}/trash")
            ->assertCreated();

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/cards")
            ->assertOk()
            ->assertJsonCount(0, 'cards');

        $entry = DB::table('trash_entries')
            ->where('resource_type', 'card')
            ->where('resource_id', $card->id)
            ->firstOrFail();

        $this->asWriter($user)
            ->postJson("/workspace/trash/{$entry->id}/restore")
            ->assertOk();

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/cards")
            ->assertOk()
            ->assertJsonCount(1, 'cards')
            ->assertJsonPath('cards.0.name', 'Elena');
    }

    public function test_trashed_novel_is_hidden_from_the_library_and_direct_reads(): void
    {
        $user = $this->writer();
        [$novel] = $this->sceneFor($user);

        $this->asWriter($user)
            ->postJson("/workspace/novel/{$novel->id}/trash")
            ->assertCreated();

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertDontSee('La carta');

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/chapters")
            ->assertNotFound();

        $this->assertDatabaseHas('novels', ['id' => $novel->id]);

        $entry = DB::table('trash_entries')
            ->where('resource_type', 'novel')
            ->where('resource_id', $novel->id)
            ->firstOrFail();

        $this->asWriter($user)
            ->postJson("/workspace/trash/{$entry->id}/restore")
            ->assertOk();

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/chapters")
            ->assertOk();
    }

    public function test_permanent_delete_requires_typed_confirmation_and_reports_dependencies(): void
    {
        $user = $this->writer();
        [, $chapter, , $scene] = $this->sceneFor($user);
        $universe = Universe::findOrFail(Novel::findOrFail($chapter->novel_id)->universe_id);
        $card = Card::create([
            'universe_id' => $universe->id,
            'area' => 'characters',
            'name' => 'Elena',
        ]);

        $this->asWriter($user)->postJson("/workspace/card/{$card->id}/trash")->assertCreated();
        $entry = DB::table('trash_entries')->where('resource_type', 'card')->where('resource_id', $card->id)->first();

        $this->asWriter($user)
            ->deleteJson("/workspace/trash/{$entry->id}", ['confirmation' => 'incorrecta'])
            ->assertStatus(422);

        $this->assertNotNull(Card::withoutGlobalScope('trashed')->find($card->id));

        TextReference::create([
            'scene_id' => $scene->id,
            'card_id' => $card->id,
            'created_by' => $user->id,
        ]);

        $this->asWriter($user)
            ->getJson("/workspace/trash/{$entry->id}/dependencies")
            ->assertOk()
            ->assertJsonPath('can_delete', false)
            ->assertJsonPath('dependencies.text_references', 1);

        $this->asWriter($user)
            ->deleteJson("/workspace/trash/{$entry->id}", ['confirmation' => 'Elena'])
            ->assertStatus(409);

        $this->assertNotNull(Card::withoutGlobalScope('trashed')->find($card->id));

        DB::table('text_references')->where('card_id', $card->id)->delete();

        $this->asWriter($user)
            ->deleteJson("/workspace/trash/{$entry->id}", ['confirmation' => 'Elena'])
            ->assertOk();

        $this->assertNull(Card::find($card->id));
        $this->assertDatabaseMissing('trash_entries', ['id' => $entry->id]);
    }

    public function test_account_closure_offers_export_and_preserves_shared_attribution(): void
    {
        $owner = $this->writer();
        $collaborator = $this->writer();

        $ownerNovel = $this->novelFor($owner);
        $ownerChapter = Chapter::create(['novel_id' => $ownerNovel->id, 'title' => 'Uno', 'position' => 0]);
        $ownerAlternative = ChapterAlternative::create([
            'chapter_id' => $ownerChapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $ownerScene = Scene::create([
            'chapter_alternative_id' => $ownerAlternative->id,
            'position' => 0,
            'content' => $this->document(['Texto compartido.']),
        ]);

        DB::table('content_revisions')->insert([
            'scene_id' => $ownerScene->id,
            'revision' => 1,
            'content' => json_encode($this->document(['Texto compartido.'])),
            'author_id' => $collaborator->id,
            'reason' => 'save',
            'created_at' => now(),
        ]);

        $ownNovel = $this->novelFor($collaborator);
        DB::table('sessions')->insert([
            'id' => 'session-collaborator',
            'user_id' => $collaborator->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->asWriter($collaborator)
            ->getJson('/account/export')
            ->assertOk()
            ->assertJsonStructure(['owned' => ['novels'], 'contributions']);

        $this->actingAs($collaborator)
            ->withSession(array_merge($this->sessionKeys(), ['reauth_at' => now()->timestamp]))
            ->postJson('/account/close')
            ->assertOk()
            ->assertJsonPath('closed', true)
            ->assertJsonPath('export_available', true);

        $this->assertNotNull(DB::table('users')->where('id', $collaborator->id)->value('closed_at'));
        $this->assertDatabaseMissing('creative_memberships', ['user_id' => $collaborator->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $collaborator->id)->count());
        $this->assertNotNull(Novel::find($ownNovel->id));
        $this->assertNotNull(Novel::find($ownerNovel->id));
        $this->assertDatabaseHas('content_revisions', ['scene_id' => $ownerScene->id, 'author_id' => $collaborator->id]);
    }
}
