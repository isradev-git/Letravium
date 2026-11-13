<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\EditLease;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CollaborationTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universe(User $owner, string $name = 'Arven'): Universe
    {
        return Universe::create(['owner_id' => $owner->id, 'name' => $name]);
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

    private function scene(Chapter $chapter, string $text): Scene
    {
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
            ],
        ]);
    }

    private function grant(User $owner, User $user, string $type, int $id, string $scope): int
    {
        return (int) $this->asWriter($owner)
            ->postJson('/workspace/collaboration/permissions', [
                'user_id' => $user->id,
                'resource_type' => $type,
                'resource_id' => $id,
                'scope' => $scope,
            ])
            ->assertCreated()
            ->json('permission.id');
    }

    public function test_grants_are_narrow_and_never_propagate_to_linked_resources(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $this->grant($owner, $reader, 'chapter', $first->id, 'read');

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters/{$first->id}")
            ->assertOk()
            ->assertJsonPath('chapter.id', $first->id);

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters/{$second->id}")
            ->assertNotFound();

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}")
            ->assertNotFound();

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/cards/{$card->id}")
            ->assertNotFound();

        $list = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters")
            ->assertOk()
            ->json();

        $this->assertCount(1, $list['chapters']);
        $this->assertSame('Uno', $list['chapters'][0]['title']);
        $this->assertStringNotContainsString('Dos', json_encode($list));

        $this->grant($owner, $reader, 'card', $card->id, 'read');

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('card.id', $card->id);

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}")
            ->assertNotFound();
    }

    public function test_a_novel_grant_covers_its_contained_chapters(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $sibling = $this->novel($universe, $owner, 'La sombra');

        $this->grant($owner, $editor, 'novel', $novel->id, 'read');

        $this->asWriter($editor)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters")
            ->assertOk()
            ->assertJsonCount(2, 'chapters');

        $this->asWriter($editor)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters/{$second->id}")
            ->assertOk();

        $this->asWriter($editor)
            ->getJson("/workspace/collaboration/novels/{$sibling->id}")
            ->assertNotFound();
    }

    public function test_only_the_owner_grants_and_an_admin_without_grants_gets_nothing(): void
    {
        $owner = $this->writer();
        $intruder = $this->writer();
        $admin = $this->admin();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $this->asWriter($owner)
            ->postJson('/workspace/collaboration/permissions', [
                'user_id' => $intruder->id,
                'resource_type' => 'novel',
                'resource_id' => $novel->id,
                'scope' => 'read',
            ])->assertCreated();

        $this->asWriter($intruder)
            ->postJson('/workspace/collaboration/permissions', [
                'user_id' => $intruder->id,
                'resource_type' => 'novel',
                'resource_id' => $novel->id,
                'scope' => 'read',
            ])->assertNotFound();

        foreach ([
            "/workspace/collaboration/novels/{$novel->id}",
            "/workspace/collaboration/novels/{$novel->id}/chapters/{$chapter->id}",
            "/workspace/collaboration/cards/{$card->id}",
        ] as $path) {
            $this->asAdmin($admin)->getJson($path)->assertNotFound();
        }
    }

    public function test_revocation_is_immediate_for_an_open_session(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);

        $permission = $this->grant($owner, $reader, 'chapter', $chapter->id, 'read');

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters/{$chapter->id}")
            ->assertOk();

        $this->asWriter($owner)
            ->deleteJson("/workspace/collaboration/permissions/{$permission}")
            ->assertOk();

        $response = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/chapters/{$chapter->id}")
            ->assertNotFound();

        $this->assertStringNotContainsString('Uno', $response->getContent());

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/history")
            ->assertNotFound();

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=Uno")
            ->assertNotFound();

        $this->assertDatabaseMissing('creative_permissions', ['id' => $permission]);
    }

    public function test_reader_search_history_download_and_export_enforce_grants(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/mapa.png', 'PNG-BYTES');

        $owner = $this->writer();
        $reader = $this->writer();
        $stranger = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $third = $this->chapter($novel, 'Tres', 2);
        $this->scene($first, 'El faro ilumina la costa.');
        $this->scene($third, 'El dragón duerme en la cueva secreta.');
        $asset = MediaAsset::create([
            'owner_id' => $owner->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'mapa.png',
            'mime' => 'image/png',
            'bytes' => 10,
            'path' => 'private/mapa.png',
        ]);

        $this->grant($owner, $reader, 'chapter', $first->id, 'read');

        $visible = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=faro")
            ->assertOk()
            ->json();

        $this->assertCount(1, $visible['results']);
        $this->assertSame($first->id, $visible['results'][0]['chapter_id']);

        $hidden = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=drag%C3%B3n")
            ->assertOk()
            ->json();

        $this->assertSame([], $hidden['results']);
        $this->assertStringNotContainsString('Tres', json_encode($hidden));

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/history")
            ->assertOk()
            ->assertJsonCount(1, 'history');

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/export")
            ->assertForbidden();

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/assets/{$asset->id}/download")
            ->assertForbidden();

        $this->asWriter($stranger)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/export")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/assets/{$asset->id}/download")
            ->assertNotFound();

        $this->grant($owner, $reader, 'novel', $novel->id, 'download');

        $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/export")
            ->assertOk();

        $download = $this->asWriter($reader)
            ->get("/workspace/collaboration/novels/{$novel->id}/assets/{$asset->id}/download")
            ->assertOk()
            ->assertDownload('mapa.png');

        $this->assertSame('PNG-BYTES', $download->streamedContent());
    }

    public function test_a_lease_excludes_other_editors_and_stale_sync_cannot_overwrite(): void
    {
        $owner = $this->writer();
        $first = $this->writer();
        $second = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);

        $this->grant($owner, $first, 'chapter', $chapter->id, 'edit');
        $this->grant($owner, $second, 'chapter', $chapter->id, 'edit');

        $this->asWriter($first)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('renew_in', 30);

        $this->asWriter($second)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertStatus(409)
            ->assertJsonPath('holder_id', $first->id);

        $this->asWriter($second)
            ->getJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('lease.user_id', $first->id);

        $this->asWriter($first)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/export")
            ->assertForbidden();

        $this->asWriter($second)
            ->patchJson("/workspace/collaboration/chapters/{$chapter->id}", [
                'base_revision' => 1,
                'title' => 'Secuestrado',
            ])->assertStatus(409);

        $this->assertSame('Uno', $chapter->fresh()->title);

        $this->travel(31)->seconds();

        $this->asWriter($first)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk();

        $this->asWriter($first)
            ->patchJson("/workspace/collaboration/chapters/{$chapter->id}", [
                'base_revision' => 1,
                'title' => 'Nuevo',
            ])->assertOk()
            ->assertJsonPath('chapter.revision', 2);

        $this->travel(122)->seconds();

        $this->asWriter($second)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk();

        $this->asWriter($first)
            ->patchJson("/workspace/collaboration/chapters/{$chapter->id}", [
                'base_revision' => 1,
                'title' => 'Viejo',
            ])->assertStatus(409);

        $this->assertSame('Nuevo', $chapter->fresh()->title);
        $this->assertSame(2, (int) $chapter->fresh()->revision);

        $this->asWriter($second)
            ->deleteJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk();

        $this->asWriter($first)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
            ->assertOk();

        $this->asWriter($first)
            ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}/expire")
            ->assertOk();
    }

    public function test_lease_creation_duplicate_returns_a_conflict_instead_of_a_database_error(): void
    {
        $owner = $this->writer();
        $first = $this->writer();
        $second = $this->writer();
        $chapter = $this->chapter($this->novel($this->universe($owner), $owner), 'Uno', 0);

        $this->grant($owner, $first, 'chapter', $chapter->id, 'edit');
        $this->grant($owner, $second, 'chapter', $chapter->id, 'edit');

        EditLease::creating(function (): void {
            throw new UniqueConstraintViolationException(
                'mysql',
                'insert into edit_leases',
                [],
                new \PDOException('duplicate lease'),
            );
        });

        try {
            $this->asWriter($second)
                ->postJson("/workspace/collaboration/leases/chapter/{$chapter->id}")
                ->assertStatus(409)
                ->assertJsonPath('message', 'Reservado por otro editor.');
        } finally {
            EditLease::flushEventListeners();
        }

        $this->assertDatabaseCount('edit_leases', 0);
    }

    public function test_multi_element_operations_are_all_or_nothing(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        $other = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);

        foreach ([$first, $second] as $chapter) {
            $this->grant($owner, $editor, 'chapter', $chapter->id, 'edit');
            $this->grant($owner, $other, 'chapter', $chapter->id, 'edit');
        }

        $this->asWriter($other)
            ->postJson("/workspace/collaboration/leases/chapter/{$second->id}")
            ->assertOk();

        $this->asWriter($editor)
            ->postJson('/workspace/collaboration/batch', [
                'operations' => [
                    ['resource_type' => 'chapter', 'resource_id' => $first->id, 'base_revision' => 1, 'title' => 'Cambio uno'],
                    ['resource_type' => 'chapter', 'resource_id' => $second->id, 'base_revision' => 1, 'title' => 'Cambio dos'],
                ],
            ])->assertStatus(409);

        $this->assertSame('Uno', $first->fresh()->title);
        $this->assertSame('Dos', $second->fresh()->title);
        $this->assertDatabaseMissing('edit_leases', [
            'resource_type' => 'chapter',
            'resource_id' => $first->id,
        ]);

        $this->asWriter($other)
            ->deleteJson("/workspace/collaboration/leases/chapter/{$second->id}")
            ->assertOk();

        $this->asWriter($editor)
            ->postJson('/workspace/collaboration/batch', [
                'operations' => [
                    ['resource_type' => 'chapter', 'resource_id' => $first->id, 'base_revision' => 99, 'title' => 'Cambio uno'],
                    ['resource_type' => 'chapter', 'resource_id' => $second->id, 'base_revision' => 1, 'title' => 'Cambio dos'],
                ],
            ])->assertStatus(409);

        $this->assertSame('Uno', $first->fresh()->title);
        $this->assertSame('Dos', $second->fresh()->title);

        $this->asWriter($editor)
            ->postJson('/workspace/collaboration/batch', [
                'operations' => [
                    ['resource_type' => 'chapter', 'resource_id' => $second->id, 'base_revision' => 1, 'title' => 'Cambio dos'],
                    ['resource_type' => 'chapter', 'resource_id' => $first->id, 'base_revision' => 1, 'title' => 'Cambio uno'],
                ],
            ])->assertOk();

        $this->assertSame('Cambio uno', $first->fresh()->title);
        $this->assertSame('Cambio dos', $second->fresh()->title);
        $this->assertSame(2, (int) $first->fresh()->revision);
        $this->assertSame(2, (int) $second->fresh()->revision);
    }

    public function test_comment_list_is_cursor_paginated(): void
    {
        $owner = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $scene = $this->scene($chapter, 'Texto');

        foreach (['Uno', 'Dos', 'Tres'] as $body) {
            $this->asWriter($owner)
                ->postJson("/workspace/novels/{$novel->id}/comments", [
                    'scene_id' => $scene->id,
                    'chapter_id' => $chapter->id,
                    'body' => $body,
                ])->assertCreated();
        }

        $first = $this->asWriter($owner)
            ->getJson("/workspace/novels/{$novel->id}/comments?limit=1")
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('has_more', true);

        $this->assertNotNull($first->json('next_cursor'));
    }

    public function test_comment_grant_lets_a_collaborator_list_write_and_suggest_without_other_chapters(): void
    {
        $owner = $this->writer();
        $collaborator = $this->writer();
        $stranger = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $first = $this->chapter($novel, 'Uno', 0);
        $second = $this->chapter($novel, 'Dos', 1);
        $firstScene = $this->scene($first, 'Visible');
        $secondScene = $this->scene($second, 'Secreto');
        $this->grant($owner, $collaborator, 'chapter', $first->id, 'comment');

        $ownerComment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'chapter_id' => $first->id,
                'body' => 'Comentario del dueño.',
            ])->assertCreated()->json('comment');

        $secretComment = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $secondScene->id,
                'chapter_id' => $second->id,
                'body' => 'Comentario de otro capítulo.',
            ])->assertCreated()->json('comment');

        $comments = $this->asWriter($collaborator)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->json('comments');

        $this->assertCount(1, $comments);
        $this->assertSame($ownerComment['id'], $comments[0]['id']);

        $collaboratorComment = $this->asWriter($collaborator)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'chapter_id' => $first->id,
                'body' => 'Comentario del colaborador.',
            ])->assertCreated()->json('comment');

        $this->asWriter($collaborator)
            ->postJson("/workspace/comments/{$ownerComment['id']}/messages", ['body' => 'Respuesta autorizada.'])
            ->assertCreated();

        $this->asWriter($collaborator)
            ->postJson("/workspace/comments/{$collaboratorComment['id']}/corrections", [
                'base_revision' => 1,
                'original' => 'Visible',
                'proposed' => 'Visible corregido',
            ])->assertCreated();

        $this->asWriter($collaborator)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $secondScene->id,
                'chapter_id' => $second->id,
                'body' => 'Fuera de alcance.',
            ])->assertForbidden();

        $this->asWriter($stranger)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertNotFound();

        $reader = $this->writer();
        $this->grant($owner, $reader, 'chapter', $first->id, 'read');

        $this->asWriter($reader)
            ->getJson("/workspace/novels/{$novel->id}/comments")
            ->assertOk();

        $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'scene_id' => $firstScene->id,
                'chapter_id' => $first->id,
                'body' => 'Un lector no comenta.',
            ])->assertForbidden();
    }

    public function test_vocabulary_is_scoped_to_the_authorized_novel_and_universe(): void
    {
        $owner = $this->writer();
        $guest = $this->writer();
        $universe = $this->universe($owner, 'Arven');
        $otherUniverse = $this->universe($owner, 'Otra');
        $novel = $this->novel($universe, $owner, 'La carta');
        $sibling = $this->novel($universe, $owner, 'La sombra');
        $foreign = $this->novel($otherUniverse, $owner, 'Secreta');

        foreach (['Valdara', 'Sural'] as $word) {
            $this->asWriter($owner)
                ->postJson("/workspace/collaboration/universes/{$universe->id}/accepted-words", ['word' => $word])
                ->assertCreated();
        }

        $this->asWriter($owner)
            ->postJson("/workspace/collaboration/universes/{$otherUniverse->id}/accepted-words", ['word' => 'Oculto'])
            ->assertCreated();

        $this->asWriter($owner)
            ->postJson("/workspace/collaboration/universes/{$universe->id}/vocabulary-grants", [
                'user_id' => $guest->id,
                'novel_id' => $novel->id,
            ])->assertCreated();

        $words = $this->asWriter($guest)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/vocabulary")
            ->assertOk()
            ->json('words');

        $this->assertContains('Valdara', $words);
        $this->assertContains('Sural', $words);
        $this->assertNotContains('Oculto', $words);

        $this->asWriter($guest)
            ->postJson("/workspace/collaboration/novels/{$novel->id}/spelling/check", ['word' => 'Valdara'])
            ->assertOk()
            ->assertJson(['known' => true]);

        $unknown = $this->asWriter($guest)
            ->postJson("/workspace/collaboration/novels/{$novel->id}/spelling/check", ['word' => 'Oculto'])
            ->assertOk()
            ->assertJson(['known' => false, 'suggestions' => []])
            ->json();

        $this->assertStringNotContainsString('Valdara', json_encode($unknown));

        $this->asWriter($guest)
            ->getJson("/workspace/collaboration/novels/{$sibling->id}/vocabulary")
            ->assertNotFound();

        $this->asWriter($guest)
            ->getJson("/workspace/collaboration/novels/{$foreign->id}/vocabulary")
            ->assertNotFound();

        $this->asWriter($guest)
            ->getJson("/workspace/collaboration/universes/{$otherUniverse->id}/accepted-words")
            ->assertNotFound();

        $this->asWriter($guest)
            ->postJson("/workspace/collaboration/universes/{$universe->id}/accepted-words", ['word' => 'Ajeno'])
            ->assertNotFound();

        $this->asWriter($owner)
            ->deleteJson("/workspace/collaboration/universes/{$universe->id}/accepted-words/Valdara")
            ->assertOk();

        $this->asWriter($guest)
            ->postJson("/workspace/collaboration/novels/{$novel->id}/spelling/check", ['word' => 'Valdara'])
            ->assertOk()
            ->assertJson(['known' => false]);
    }

    public function test_reader_search_uses_the_same_accent_and_case_modes_as_the_owner(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $universe = $this->universe($owner);
        $novel = $this->novel($universe, $owner);
        $chapter = $this->chapter($novel, 'Uno', 0);
        $this->scene($chapter, 'La caña del río.');
        $this->grant($owner, $reader, 'chapter', $chapter->id, 'read');

        $accentInsensitive = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=ca%C3%B1a")
            ->assertOk()
            ->json();
        $this->assertCount(1, $accentInsensitive['results']);
        $this->assertSame(1, $accentInsensitive['count']);

        $distinctEne = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=cana")
            ->assertOk()
            ->json();
        $this->assertSame([], $distinctEne['results']);

        $caseSensitive = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=CA%C3%91A&case=sensitive")
            ->assertOk()
            ->json();
        $this->assertSame([], $caseSensitive['results']);

        $scenesOnly = $this->asWriter($reader)
            ->getJson("/workspace/collaboration/novels/{$novel->id}/search?q=ca%C3%B1a&type=scene")
            ->assertOk()
            ->json();
        $this->assertCount(1, $scenesOnly['results']);
    }
}
