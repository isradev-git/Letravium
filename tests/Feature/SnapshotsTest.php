<?php

namespace Tests\Feature;

use App\Models\BetaReader;
use App\Models\BookMark;
use App\Models\BookSection;
use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\EditLease;
use App\Models\Footnote;
use App\Models\Novel;
use App\Models\Part;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Universe;
use App\Models\User;
use App\Models\WorkState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SnapshotsTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universe(User $owner): Universe
    {
        return Universe::create(['owner_id' => $owner->id, 'name' => 'Arven']);
    }

    private function novel(Universe $universe, User $owner, string $title = 'La carta'): Novel
    {
        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $owner->id,
            'title' => $title,
        ]);
    }

    private function chapter(Novel $novel, string $title, int $position): Chapter
    {
        return Chapter::create([
            'novel_id' => $novel->id,
            'title' => $title,
            'position' => $position,
        ]);
    }

    private function scene(Chapter $chapter, string $text, int $position = 0): Scene
    {
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => $position,
            'content' => $this->document($text),
        ]);
    }

    private function document(string $text): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
        ];
    }

    private function makeSnapshot(User $owner, Novel $novel, string $name = 'Versión 1'): array
    {
        return $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => $name])
            ->assertCreated()
            ->json('snapshot');
    }

    public function test_snapshot_content_is_immutable_and_rename_keeps_manifest(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Original');

        $snapshot = $this->makeSnapshot($owner, $novel);

        $this->assertSame('Original', $snapshot['manifest']['chapters'][0]['alternatives'][0]['scenes'][0]['content']['blocks'][0]['text']);

        $this->asWriter($owner)
            ->patchJson("/workspace/snapshots/{$snapshot['id']}", ['name' => 'Renombrada'])
            ->assertOk()
            ->assertJsonPath('snapshot.name', 'Renombrada');

        $fresh = Snapshot::findOrFail($snapshot['id']);
        $this->assertSame('Original', $fresh->manifest['chapters'][0]['alternatives'][0]['scenes'][0]['content']['blocks'][0]['text']);

        $scene->forceFill(['content' => $this->document('Cambiado'), 'revision' => 2])->save();
        $item = Snapshot::findOrFail($snapshot['id'])->items()->where('resource_type', 'scene')->firstOrFail();
        $this->assertSame('Original', $item->payload['content']['blocks'][0]['text']);

        $this->expectException(\LogicException::class);
        Snapshot::findOrFail($snapshot['id'])->forceFill(['manifest' => ['hacked' => true]])->save();
    }

    public function test_fragment_restore_rejects_stale_revision_and_preserves_replaced_text(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Original');

        $snapshot = $this->makeSnapshot($owner, $novel);

        $scene->forceFill(['content' => $this->document('Cambiado'), 'revision' => 2])->save();

        $changes = $this->asWriter($owner)
            ->getJson("/workspace/snapshots/{$snapshot['id']}/compare")
            ->assertOk()
            ->json('changes');

        $change = collect($changes)->firstWhere(fn ($item) => $item['resource_type'] === 'scene' && $item['resource_id'] === $scene->id);
        $this->assertNotNull($change);
        $this->assertSame('modified', $change['status']);
        $this->assertContains('content', $change['fields']);

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore-fragment", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'expected_revision' => 1,
                'confirm' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'stale_revision');

        $this->assertSame('Cambiado', $scene->fresh()->content['blocks'][0]['text']);

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore-fragment", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'expected_revision' => 2,
                'confirm' => true,
            ])
            ->assertOk();

        $scene->refresh();
        $this->assertSame('Original', $scene->content['blocks'][0]['text']);
        $this->assertSame(3, (int) $scene->revision);

        $history = DB::table('content_revisions')
            ->where('scene_id', $scene->id)
            ->where('reason', 'fragment_restore')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('Cambiado', json_decode($history->content, true)['blocks'][0]['text']);
    }

    public function test_fragment_restore_can_target_one_block_and_keep_other_current_blocks(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Original');

        $scene->forceFill(['content' => [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'text' => 'Original'],
                ['id' => 'b2', 'type' => 'paragraph', 'text' => 'Segundo'],
            ],
        ]])->save();

        $snapshot = $this->makeSnapshot($owner, $novel);

        $scene->forceFill(['content' => [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'text' => 'Cambiado'],
                ['id' => 'b2', 'type' => 'paragraph', 'text' => 'Segundo actual'],
            ],
        ], 'revision' => 2])->save();

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore-fragment", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'block_id' => 'b1',
                'expected_revision' => 2,
                'confirm' => true,
            ])
            ->assertOk();

        $scene->refresh();
        $this->assertSame('Original', $scene->content['blocks'][0]['text']);
        $this->assertSame('Segundo actual', $scene->content['blocks'][1]['text']);
        $this->assertSame(3, (int) $scene->revision);
    }

    public function test_complete_restore_keeps_previous_state_and_leaves_universe_cards_alone(): void
    {
        $owner = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Primero', 0);
        $second = $this->chapter($novel, 'Segundo', 1);
        $firstScene = $this->scene($first, 'Uno');
        $this->scene($second, 'Dos');
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $snapshot = $this->makeSnapshot($owner, $novel);

        $first->forceFill(['title' => 'Cambiado', 'position' => 1])->save();
        $second->forceFill(['position' => 0])->save();
        $firstScene->forceFill(['content' => $this->document('Uno nuevo'), 'revision' => 2])->save();
        $third = $this->chapter($novel, 'Añadido', 2);
        $this->scene($third, 'Tres');
        $card->forceFill(['name' => 'Elena cambiada'])->save();

        foreach ([$first->id, $second->id, $third->id] as $chapterId) {
            $this->asWriter($owner)
                ->postJson("/workspace/collaboration/leases/chapter/{$chapterId}")
                ->assertOk();
        }

        $preview = $this->asWriter($owner)
            ->getJson("/workspace/snapshots/{$snapshot['id']}/restore-preview")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($preview['changes']);

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore", [
                'revisions' => $preview['revisions'],
                'confirm' => true,
            ])
            ->assertOk();

        $ordered = Chapter::where('novel_id', $novel->id)->orderBy('position')->get();
        $this->assertSame(['Primero', 'Segundo'], $ordered->pluck('title')->all());
        $this->assertSame('Uno', $firstScene->fresh()->content['blocks'][0]['text']);
        $this->assertFalse(Chapter::whereKey($third->id)->exists());

        $this->assertSame('Elena cambiada', $card->fresh()->name);
        $this->assertNotNull(Snapshot::where('novel_id', $novel->id)->where('name', 'like', 'Antes de restaurar%')->first());

        $history = DB::table('content_revisions')
            ->where('scene_id', $firstScene->id)
            ->where('reason', 'full_restore')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('Uno nuevo', json_decode($history->content, true)['blocks'][0]['text']);

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore", [
                'revisions' => $preview['revisions'],
                'confirm' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'stale_revision');
    }

    public function test_complete_restore_revives_a_chapter_that_was_trashed_after_the_snapshot(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $this->scene($chapter, 'Texto');

        $snapshot = $this->makeSnapshot($owner, $novel);

        $this->asWriter($owner)
            ->postJson("/workspace/chapter/{$chapter->id}/trash")
            ->assertStatus(201);

        $this->assertNull(Chapter::find($chapter->id));

        $preview = $this->asWriter($owner)
            ->getJson("/workspace/snapshots/{$snapshot['id']}/restore-preview")
            ->assertOk()
            ->json();

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore", [
                'revisions' => $preview['revisions'],
                'confirm' => true,
            ])
            ->assertOk();

        $restored = Chapter::find($chapter->id);
        $this->assertNotNull($restored);
        $this->assertSame('Uno', $restored->title);
        $this->assertDatabaseMissing('trash_entries', ['resource_type' => 'chapter', 'resource_id' => $chapter->id]);
        $this->assertSame(1, Chapter::where('novel_id', $novel->id)->where('title', 'Uno')->count());
    }

    public function test_comment_anchor_keeps_original_quote_and_marks_changed_text_for_review(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'El puente viejo');

        $comment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $scene->id,
                'block_id' => 'b1',
                'quoted_text' => 'El puente viejo',
                'body' => '¿Es el puente correcto?',
            ])
            ->assertCreated()
            ->json('comment');

        $this->asWriter($owner)
            ->postJson("/workspace/comments/{$comment['id']}/messages", ['body' => 'Segundo mensaje.'])
            ->assertCreated();

        $scene->forceFill(['content' => $this->document('El río'), 'revision' => 2])->save();

        $comments = $this->asWriter($owner)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->json('comments');

        $this->assertSame('needs_review', $comments[0]['anchor_status']);
        $this->assertSame('El puente viejo', $comments[0]['quoted_text']);

        $snapshot = $this->makeSnapshot($owner, $novel, 'Revisión beta');

        $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $scene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'quoted_text' => 'El río',
                'body' => 'Nota sobre la versión revisada.',
            ])
            ->assertCreated();

        $scene->forceFill(['content' => $this->document('Otra cosa'), 'revision' => 3])->save();

        $comments = $this->asWriter($owner)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->json('comments');

        $anchored = collect($comments)->firstWhere('snapshot_id', $snapshot['id']);
        $this->assertSame('anchored', $anchored['anchor_status']);
        $this->assertSame('El río', $anchored['quoted_text']);

        $this->asWriter($owner)
            ->patchJson("/workspace/comments/{$comment['id']}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('comment.status', 'resolved')
            ->assertJsonPath('comment.quoted_text', 'El puente viejo')
            ->assertJsonCount(2, 'comment.messages');

        $this->asWriter($this->writer())
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertNotFound();
    }

    public function test_correction_requires_lease_and_current_base_then_creates_history(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Original');

        $comment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $scene->id,
                'block_id' => 'b1',
                'quoted_text' => 'Original',
                'body' => 'Sugerencia.',
            ])
            ->assertCreated()
            ->json('comment');

        $rejected = $this->asWriter($owner)
            ->postJson("/workspace/comments/{$comment['id']}/corrections", [
                'base_revision' => 1,
                'original' => 'Original',
                'proposed' => 'Rechazada',
            ])
            ->assertCreated()
            ->json('correction');

        $this->asWriter($owner)
            ->postJson("/workspace/corrections/{$rejected['id']}/reject")
            ->assertOk()
            ->assertJsonPath('correction.status', 'rejected');

        $this->assertSame('Original', $scene->fresh()->content['blocks'][0]['text']);

        $proposal = $this->asWriter($owner)
            ->postJson("/workspace/comments/{$comment['id']}/corrections", [
                'base_revision' => 1,
                'original' => 'Original',
                'proposed' => 'Corregido',
            ])
            ->assertCreated()
            ->json('correction');

        $this->asWriter($owner)
            ->postJson("/workspace/corrections/{$proposal['id']}/accept")
            ->assertStatus(403);

        $this->asWriter($owner)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk();

        $stale = $this->asWriter($owner)
            ->postJson("/workspace/comments/{$comment['id']}/corrections", [
                'base_revision' => 5,
                'original' => 'Original',
                'proposed' => 'Vieja',
            ])
            ->assertCreated()
            ->json('correction');

        $this->asWriter($owner)
            ->postJson("/workspace/corrections/{$stale['id']}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error', 'stale_target');

        $this->assertSame('Original', $scene->fresh()->content['blocks'][0]['text']);

        $this->asWriter($owner)
            ->postJson("/workspace/corrections/{$proposal['id']}/accept")
            ->assertOk()
            ->assertJsonPath('correction.status', 'accepted');

        $scene->refresh();
        $this->assertSame('Corregido', $scene->content['blocks'][0]['text']);
        $this->assertSame(2, (int) $scene->revision);

        $history = DB::table('content_revisions')
            ->where('scene_id', $scene->id)
            ->where('reason', 'correction')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('Original', json_decode($history->content, true)['blocks'][0]['text']);

        $this->asWriter($this->writer())
            ->postJson("/workspace/corrections/{$proposal['id']}/accept")
            ->assertNotFound();
    }

    public function test_beta_reader_cannot_reject_a_correction_without_accept_permission(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Original');
        $snapshot = $this->makeSnapshot($owner, $novel, 'Borrador beta');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'can_comment' => true,
                'scope' => ['chapters' => [$chapter->id]],
            ])->assertCreated()->json('beta_reader');

        $comment = $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $scene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'quoted_text' => 'Original',
                'body' => 'Sugerencia del lector.',
            ])->assertCreated()->json('comment');

        $proposal = $this->asWriter($reader)
            ->postJson("/workspace/comments/{$comment['id']}/corrections", [
                'base_revision' => 1,
                'original' => 'Original',
                'proposed' => 'Corregido',
            ])->assertCreated()->json('correction');

        $this->asWriter($reader)
            ->postJson("/workspace/corrections/{$proposal['id']}/reject")
            ->assertNotFound();

        $this->asWriter($owner)
            ->postJson("/workspace/corrections/{$proposal['id']}/reject")
            ->assertOk()
            ->assertJsonPath('correction.status', 'rejected');

        $this->assertNotNull($assignment['token']);
    }

    public function test_beta_reader_is_pinned_to_the_assigned_snapshot_and_can_comment(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $firstScene = $this->scene($first, 'Uno');
        $secondScene = $this->scene($second, 'Dos');
        Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $secondScene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota fuera de alcance.',
        ]);

        $snapshot = $this->makeSnapshot($owner, $novel, 'Borrador beta');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'can_comment' => true,
                'scope' => ['chapters' => [$first->id]],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $token = $assignment['token'];

        $firstScene->forceFill(['content' => $this->document('Uno cambiado'), 'revision' => 2])->save();

        $content = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$token}")
            ->assertOk()
            ->json('content');

        $this->assertCount(1, $content['chapters']);
        $this->assertSame('Uno', $content['chapters'][0]['alternatives'][0]['scenes'][0]['content']['blocks'][0]['text']);
        $this->assertStringNotContainsString('Dos', json_encode($content));
        $this->assertStringNotContainsString('Nota fuera de alcance.', json_encode($content));

        $comment = $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'quoted_text' => 'Uno',
                'body' => 'Comentario de lector beta.',
            ])
            ->assertCreated()
            ->json('comment');

        $this->assertSame($snapshot['id'], $comment['snapshot_id']);

        $firstScene->forceFill(['content' => $this->document('Uno otra vez'), 'revision' => 3])->save();

        $content = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$token}")
            ->assertOk()
            ->json('content');

        $this->assertSame('Uno', $content['chapters'][0]['alternatives'][0]['scenes'][0]['content']['blocks'][0]['text']);

        $comments = $this->asWriter($reader)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->json('comments');

        $this->assertSame('anchored', $comments[0]['anchor_status']);
        $this->assertSame('Uno', $comments[0]['quoted_text']);

        $this->asWriter($this->writer())
            ->getJson("/workspace/beta/{$token}")
            ->assertForbidden();

        BetaReader::whereKey($assignment['id'])->update(['expires_at' => now()->subDay()]);

        $this->asWriter($reader)
            ->getJson("/workspace/beta/{$token}")
            ->assertForbidden();
    }

    public function test_beta_reader_messages_require_comment_permission_and_an_active_assignment(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $firstScene = $this->scene($first, 'Uno');
        $snapshot = $this->makeSnapshot($owner, $novel, 'Borrador beta');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'can_comment' => false,
                'scope' => ['chapters' => [$first->id]],
            ])->assertCreated()->json('beta_reader');

        $comment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'body' => 'Comentario del dueño.',
            ])->assertCreated()->json('comment');

        $this->asWriter($reader)
            ->postJson("/workspace/comments/{$comment['id']}/messages", ['body' => 'No debería poder responder.'])
            ->assertForbidden();

        BetaReader::whereKey($assignment['id'])->update(['can_comment' => true]);

        $own = $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'body' => 'Comentario del lector.',
            ])->assertCreated()->json('comment');

        BetaReader::whereKey($assignment['id'])->update(['expires_at' => now()->subDay()]);

        $this->asWriter($reader)
            ->postJson("/workspace/comments/{$own['id']}/messages", ['body' => 'Respuesta tras caducar.'])
            ->assertForbidden();
    }

    public function test_scoped_beta_reader_cannot_write_outside_the_assigned_chapter(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $this->scene($first, 'Uno');
        $secondScene = $this->scene($second, 'Dos');

        $snapshot = $this->makeSnapshot($owner, $novel, 'Borrador beta');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'can_comment' => true,
                'scope' => ['chapters' => [$first->id]],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $comment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $secondScene->id,
                'snapshot_id' => $snapshot['id'],
                'block_id' => 'b1',
                'quoted_text' => 'Dos',
                'body' => 'Comentario fuera de alcance.',
            ])
            ->assertCreated()
            ->json('comment');

        $this->asWriter($reader)
            ->postJson("/workspace/comments/{$comment['id']}/messages", [
                'body' => 'Mensaje fuera de alcance.',
            ])
            ->assertForbidden();

        $this->asWriter($reader)
            ->postJson("/workspace/comments/{$comment['id']}/corrections", [
                'base_revision' => 1,
                'original' => 'Dos',
                'proposed' => 'Dos corregido.',
            ])
            ->assertForbidden();
    }

    public function test_fragment_restore_requires_the_chapter_lease(): void
    {
        $owner = $this->writer();
        $collaborator = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Base');

        $snapshot = $this->makeSnapshot($owner, $novel, 'Versión');

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

        $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/restore-fragment", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'expected_revision' => 1,
                'confirm' => true,
            ])
            ->assertForbidden();
    }

    public function test_snapshot_index_returns_summaries_without_full_manifests(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $this->chapter($novel, 'Uno', 0);
        $this->makeSnapshot($owner, $novel, 'Versión resumida');

        $response = $this->asWriter($owner)
            ->getJson("/workspace/novels/{$novel->id}/snapshots")
            ->assertOk()
            ->assertJsonPath('snapshots.0.name', 'Versión resumida')
            ->assertJsonMissingPath('snapshots.0.manifest')
            ->assertJsonMissingPath('snapshots.0.items');

        $this->assertArrayHasKey('created_at', $response->json('snapshots.0'));
    }

    public function test_scoped_beta_reader_does_not_receive_out_of_scope_cards_parts_or_states(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);

        $visibleCard = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena visible']);
        $secretCard = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Secreto futuro']);

        $visiblePart = Part::create(['novel_id' => $novel->id, 'title' => 'Parte visible', 'position' => 0]);
        $secretPart = Part::create(['novel_id' => $novel->id, 'title' => 'Parte futura', 'position' => 1]);
        $visibleState = WorkState::create(['novel_id' => $novel->id, 'name' => 'Estado visible', 'position' => 0]);
        $secretState = WorkState::create(['novel_id' => $novel->id, 'name' => 'Estado futuro', 'position' => 1]);

        $first->forceFill(['part_id' => $visiblePart->id])->save();
        $second->forceFill(['part_id' => $secretPart->id])->save();
        $firstScene = $this->scene($first, 'Visible');
        $secondScene = $this->scene($second, 'Secreto');
        $firstScene->forceFill(['place_card_id' => $visibleCard->id])->save();
        $secondScene->forceFill(['place_card_id' => $secretCard->id])->save();

        $first->alternatives()->update(['work_state_id' => $visibleState->id]);
        $second->alternatives()->update(['work_state_id' => $secretState->id]);

        $snapshot = $this->makeSnapshot($owner, $novel, 'Beta acotado');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'scope' => ['chapters' => [$first->id]],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $content = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$assignment['token']}")
            ->assertOk()
            ->json('content');

        $json = json_encode($content, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Secreto futuro', $json);
        $this->assertStringNotContainsString('Parte futura', $json);
        $this->assertStringNotContainsString('Estado futuro', $json);
        $this->assertStringContainsString('Elena visible', $json);
        $this->assertStringContainsString('Parte visible', $json);
        $this->assertStringContainsString('Estado visible', $json);

        $emptyAssignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'scope' => ['chapters' => []],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $empty = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$emptyAssignment['token']}")
            ->assertOk()
            ->json('content');

        $this->assertSame([], $empty['chapters']);
        $this->assertSame([], $empty['parts']);
        $this->assertSame([], $empty['work_states']);

        $cardOnly = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'scope' => ['cards' => [$visibleCard->id]],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $cards = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$cardOnly['token']}")
            ->assertOk()
            ->json('content');

        $this->assertSame([], $cards['chapters']);
        $this->assertSame([], $cards['parts']);
        $this->assertSame([], $cards['work_states']);
        $this->assertCount(1, $cards['cards']);
        $this->assertSame($visibleCard->id, $cards['cards'][0]['id']);
    }

    public function test_scoped_beta_reader_excludes_every_out_of_scope_navigation_mark_and_section(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novel($this->universe($owner), $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $firstScene = $this->scene($first, 'Visible');
        $secondScene = $this->scene($second, 'Secreto');

        $mark = fn (string $type, string $label, ?string $targetType, ?int $targetId) => BookMark::create([
            'novel_id' => $novel->id,
            'type' => $type,
            'label' => $label,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'position' => 0,
        ]);

        $mark('index', 'Detalle permitido', 'chapter', $first->id);
        $mark('index', 'Detalle secreto', 'chapter', $second->id);
        $mark('index', 'Detalle ambiguo', null, null);
        $mark('figure', 'Figura secreta', 'scene', $secondScene->id);
        $mark('figure', 'Figura permitida', 'scene', $firstScene->id);
        $mark('table', 'Tabla secreta', 'chapter', $second->id);
        $mark('glossary', 'Término secreto', 'scene', $secondScene->id);
        $mark('glossary', 'Término permitido', 'scene', $firstScene->id);

        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'back',
            'type' => 'afterword',
            'title' => 'Sección privada',
            'content' => 'Texto privado.',
            'position' => 0,
            'include_in_export' => true,
        ]);

        $snapshot = $this->makeSnapshot($owner, $novel, 'Beta acotado');

        $assignment = $this->asWriter($owner)
            ->postJson("/workspace/snapshots/{$snapshot['id']}/beta-readers", [
                'user_id' => $reader->id,
                'scope' => ['chapters' => [$first->id]],
            ])
            ->assertCreated()
            ->json('beta_reader');

        $content = $this->asWriter($reader)
            ->getJson("/workspace/beta/{$assignment['token']}")
            ->assertOk()
            ->json('content');

        $json = json_encode($content, JSON_UNESCAPED_UNICODE);

        foreach (['Detalle secreto', 'Figura secreta', 'Tabla secreta', 'Término secreto', 'Detalle ambiguo', 'Sección privada', 'Texto privado.'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        foreach (['Detalle permitido', 'Figura permitida', 'Término permitido'] as $allowed) {
            $this->assertStringContainsString($allowed, $json);
        }

        $this->assertSame([], $content['sections'] ?? []);
    }
}
