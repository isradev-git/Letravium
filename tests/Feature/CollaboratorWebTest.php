<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CollaboratorWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function project(User $owner): array
    {
        $universe = Universe::create(['owner_id' => $owner->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $owner->id, 'title' => 'La carta']);
        $first = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $second = Chapter::create(['novel_id' => $novel->id, 'title' => 'Dos', 'position' => 2]);
        $firstScene = $this->scene($first, 'Faro visible.');
        $secondScene = $this->scene($second, 'Dragón secreto.');

        return [$novel, $first, $second, $firstScene, $secondScene];
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
            'revision' => 1,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text, 'marks' => []]],
            ],
        ]);
    }

    private function grant(User $owner, User $user, string $type, int $id, string $scope): void
    {
        $this->asWriter($owner)
            ->postJson('/workspace/collaboration/permissions', [
                'user_id' => $user->id,
                'resource_type' => $type,
                'resource_id' => $id,
                'scope' => $scope,
            ])
            ->assertCreated();
    }

    public function test_a_chapter_grant_opens_a_shared_library_and_read_only_editor_without_leaking_other_chapters(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        [$novel, $first, $second] = $this->project($owner);
        $this->grant($owner, $reader, 'chapter', $first->id, 'read');

        $this->asWriter($reader)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Compartidas contigo')
            ->assertSee('La carta')
            ->assertSee('Uno')
            ->assertDontSee('>Dos<', false);

        $this->asWriter($reader)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('Faro visible.')
            ->assertDontSee('Dragón secreto.')
            ->assertSee('data-read-only="true"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}")
            ->assertNotFound();
    }

    public function test_a_granted_editor_must_hold_a_lease_and_can_save_through_the_web_endpoint(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        [$novel, $first, , $scene] = $this->project($owner);
        $this->grant($owner, $editor, 'chapter', $first->id, 'edit');

        $content = [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Corregido.', 'marks' => []]],
        ];

        $this->asWriter($editor)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $content,
            ])->assertStatus(409);

        $this->asWriter($editor)
            ->postJson("/workspace/collaboration/leases/chapter/{$first->id}")
            ->assertOk();

        $this->asWriter($editor)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $content,
            ])->assertOk()
            ->assertJsonPath('scene.revision', 2);

        $this->assertSame('Corregido.', $scene->fresh()->content['blocks'][0]['text']);

        $this->asWriter($editor)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertDontSee('data-read-only="true"', false);
    }

    public function test_an_owner_cannot_overwrite_a_chapter_held_under_a_collaborator_lease(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        [$novel, $first, , $scene] = $this->project($owner);
        $this->grant($owner, $editor, 'chapter', $first->id, 'edit');

        $this->asWriter($editor)
            ->postJson("/workspace/collaboration/leases/chapter/{$first->id}")
            ->assertOk();

        $content = [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Intento del dueño.', 'marks' => []]],
        ];

        $this->asWriter($owner)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $content,
            ])->assertStatus(409);

        $this->asWriter($owner)
            ->putJson("/workspace/scenes/{$scene->id}", [
                'base_revision' => 1,
                'content' => $content,
            ])->assertStatus(409);

        $this->assertSame('Faro visible.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_a_collaborator_can_save_a_shared_scene_that_still_contains_an_owner_image(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        [$novel, $first, , $scene] = $this->project($owner);
        $this->grant($owner, $editor, 'chapter', $first->id, 'edit');

        $asset = MediaAsset::create([
            'owner_id' => $owner->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'mapa.png',
            'mime' => 'image/png',
            'bytes' => 3,
            'path' => 'media/mapa.png',
        ]);

        $scene->forceFill(['content' => [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto original.', 'marks' => []],
                ['id' => 'i1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Mapa']],
            ],
        ]])->save();

        $this->asWriter($editor)
            ->postJson("/workspace/collaboration/leases/chapter/{$first->id}")
            ->assertOk();

        $this->asWriter($editor)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [
                        ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto corregido.', 'marks' => []],
                        ['id' => 'i1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Mapa']],
                    ],
                ],
            ])->assertOk();

        $saved = $scene->fresh();
        $this->assertSame('Texto corregido.', $saved->content['blocks'][0]['text']);
        $this->assertSame($asset->id, $saved->content['blocks'][1]['attrs']['asset_id']);
    }

    public function test_a_chapter_reader_can_view_only_images_referenced_by_accessible_scenes(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/shared.png', 'PNG');

        $owner = $this->writer();
        $reader = $this->writer();
        [$novel, $first, , $scene] = $this->project($owner);
        $this->grant($owner, $reader, 'chapter', $first->id, 'read');

        $referenced = MediaAsset::create([
            'owner_id' => $owner->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'shared.png',
            'mime' => 'image/png',
            'bytes' => 3,
            'path' => 'media/shared.png',
        ]);
        $unreferenced = MediaAsset::create([
            'owner_id' => $owner->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'private.png',
            'mime' => 'image/png',
            'bytes' => 3,
            'path' => 'media/shared.png',
        ]);

        $scene->forceFill(['content' => [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Faro visible.', 'marks' => []],
                ['id' => 'i1', 'type' => 'image', 'attrs' => ['asset_id' => $referenced->id, 'alt' => 'Mapa']],
            ],
        ]])->save();

        $this->asWriter($reader)
            ->get("/workspace/assets/{$referenced->id}/view")
            ->assertOk();

        $this->asWriter($reader)
            ->get("/workspace/assets/{$unreferenced->id}/view")
            ->assertNotFound();

        $this->asWriter($this->writer())
            ->get("/workspace/assets/{$referenced->id}/view")
            ->assertNotFound();
    }

    public function test_revocation_removes_the_shared_library_and_editor_access(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        [$novel, $first] = $this->project($owner);
        $permission = $this->asWriter($owner)
            ->postJson('/workspace/collaboration/permissions', [
                'user_id' => $reader->id,
                'resource_type' => 'chapter',
                'resource_id' => $first->id,
                'scope' => 'read',
            ])->assertCreated()->json('permission.id');

        $this->asWriter($reader)->get('/workspace')->assertSee('La carta');

        $this->asWriter($owner)
            ->deleteJson("/workspace/collaboration/permissions/{$permission}")
            ->assertOk();

        $this->asWriter($reader)
            ->get("/workspace/novels/{$novel->id}")
            ->assertNotFound();
    }
}
