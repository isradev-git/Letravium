<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ReplacementTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function sceneFor(Novel $novel, string $text, string $title = 'Uno'): Scene
    {
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => $title,
            'position' => (int) $novel->chapters()->max('position') + 1,
        ]);
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
                'blocks' => [[
                    'id' => 'b'.$chapter->id,
                    'type' => 'paragraph',
                    'text' => $text,
                    'marks' => [['type' => 'bold', 'start' => 0, 'end' => 4]],
                ]],
            ],
        ]);
    }

    private function payload(array $chapterIds, array $overrides = []): array
    {
        return array_merge([
            'search' => 'casa',
            'replacement' => 'vivienda',
            'chapter_ids' => $chapterIds,
            'whole_word' => true,
            'case_sensitive' => false,
        ], $overrides);
    }

    private function previewHash($response): string
    {
        preg_match('/name="preview_hash" value="([^"]+)"/', $response->getContent(), $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'The replacement preview must carry its hash.');

        return $matches[1];
    }

    public function test_search_page_offers_a_chapter_scoped_replacement_form(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->sceneFor($novel, 'casa y casamiento.', 'Uno');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/search?q=casa")
            ->assertOk()
            ->assertSee('Sustituir')
            ->assertSee('name="chapter_ids[]"', false)
            ->assertSee("/workspace/novels/{$novel->id}/replace/preview?format=html", false);
    }

    public function test_html_replacement_preview_lists_matches_and_apply_redirects(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'casa y casamiento.', 'Uno');
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/replace/preview?format=html", $this->payload($chapterIds))
            ->assertOk()
            ->assertSee('casa')
            ->assertSee('vivienda')
            ->assertSee('name="preview_hash"', false);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/replace?format=html", $this->payload($chapterIds, [
                'preview_hash' => $this->previewHash($preview),
            ]))
            ->assertRedirect("/workspace/novels/{$novel->id}/search?q=casa");

        $this->assertSame('vivienda y casamiento.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_html_replacement_apply_reports_a_stale_preview_without_writing(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'casa');
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/replace/preview?format=html", $this->payload($chapterIds))
            ->assertOk();

        $scene->forceFill([
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'changed', 'type' => 'paragraph', 'text' => 'casa']]],
            'revision' => 2,
        ])->save();

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/replace?format=html", $this->payload($chapterIds, [
                'preview_hash' => $this->previewHash($preview),
            ]))
            ->assertRedirect("/workspace/novels/{$novel->id}/search?q=casa")
            ->assertSessionHasErrors('replace');

        $this->assertSame('casa', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_replacement_preview_is_bounded_and_truncated_apply_is_rejected(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'x', 'Uno');
        $blocks = [];

        for ($index = 0; $index < 1001; $index++) {
            $blocks[] = ['id' => 'b'.$index, 'type' => 'paragraph', 'text' => 'x'];
        }

        $scene->forceFill(['content' => ['schema_version' => 1, 'blocks' => $blocks]])->save();
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload($chapterIds, [
                'search' => 'x',
                'replacement' => 'y',
            ]))
            ->assertOk()
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('count', 1000)
            ->json();

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload($chapterIds, [
                'search' => 'x',
                'replacement' => 'y',
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertStatus(422);
    }

    public function test_preview_and_apply_replace_whole_words_across_chapters_preserving_marks(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $first = $this->sceneFor($novel, 'casa y casamiento.', 'Uno');
        $second = $this->sceneFor($novel, 'Casa nueva.', 'Dos');
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload($chapterIds))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->json();

        $this->assertCount(2, $preview['matches']);
        $this->assertNotEmpty($preview['preview_hash']);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload($chapterIds, [
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertOk()
            ->assertJsonPath('replaced', 2);

        $this->assertSame('vivienda y casamiento.', $first->fresh()->content['blocks'][0]['text']);
        $this->assertSame('vivienda nueva.', $second->fresh()->content['blocks'][0]['text']);
        $this->assertSame(2, (int) $first->fresh()->revision);
        $this->assertSame(2, (int) $second->fresh()->revision);

        $marks = $first->fresh()->content['blocks'][0]['marks'];
        $this->assertSame('bold', $marks[0]['type']);
        $this->assertSame(0, $marks[0]['start']);
        $this->assertSame(8, $marks[0]['end']);
    }

    public function test_preview_is_stale_when_a_scene_changes_after_it_was_prepared(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel, 'casa');
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload($chapterIds))
            ->assertOk()
            ->json();

        $scene->forceFill([
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'changed', 'type' => 'paragraph', 'text' => 'casa']]],
            'revision' => 2,
        ])->save();

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload($chapterIds, [
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertStatus(409);

        $this->assertSame('casa', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_apply_is_atomic_when_another_editor_holds_a_chapter_lease(): void
    {
        $owner = $this->writer();
        $other = $this->writer();
        $novel = $this->novelFor($owner);
        $first = $this->sceneFor($novel, 'casa uno', 'Uno');
        $second = $this->sceneFor($novel, 'casa dos', 'Dos');
        $chapterIds = $novel->chapters()->pluck('id')->all();

        $preview = $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload($chapterIds))
            ->assertOk()
            ->json();

        CreativePermission::create([
            'user_id' => $other->id,
            'resource_type' => 'chapter',
            'resource_id' => $chapterIds[1],
            'scope' => 'edit',
            'granted_by' => $owner->id,
        ]);
        EditLease::create([
            'resource_type' => 'chapter',
            'resource_id' => $chapterIds[1],
            'user_id' => $other->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->asWriter($owner)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload($chapterIds, [
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertStatus(423);

        $this->assertSame('casa uno', $first->fresh()->content['blocks'][0]['text']);
        $this->assertSame('casa dos', $second->fresh()->content['blocks'][0]['text']);
    }

    public function test_collaborator_can_apply_only_with_edit_permission(): void
    {
        $owner = $this->writer();
        $reader = $this->writer();
        $novel = $this->novelFor($owner);
        $scene = $this->sceneFor($novel, 'casa');
        $chapterId = $novel->chapters()->value('id');

        CreativePermission::create([
            'user_id' => $reader->id,
            'resource_type' => 'chapter',
            'resource_id' => $chapterId,
            'scope' => 'read',
            'granted_by' => $owner->id,
        ]);

        $preview = $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload([$chapterId]))
            ->assertOk()
            ->json();

        $this->asWriter($reader)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload([$chapterId], [
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertForbidden();

        $this->assertSame('casa', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_collaborator_with_edit_permission_and_own_lease_can_apply(): void
    {
        $owner = $this->writer();
        $editor = $this->writer();
        $novel = $this->novelFor($owner);
        $scene = $this->sceneFor($novel, 'casa');
        $chapterId = $novel->chapters()->value('id');

        CreativePermission::create([
            'user_id' => $editor->id,
            'resource_type' => 'chapter',
            'resource_id' => $chapterId,
            'scope' => 'edit',
            'granted_by' => $owner->id,
        ]);
        EditLease::create([
            'resource_type' => 'chapter',
            'resource_id' => $chapterId,
            'user_id' => $editor->id,
            'expires_at' => now()->addMinute(),
        ]);

        $preview = $this->asWriter($editor)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload([$chapterId]))
            ->assertOk()
            ->json();

        $this->asWriter($editor)
            ->postJson("/workspace/novels/{$novel->id}/replace", $this->payload([$chapterId], [
                'preview_hash' => $preview['preview_hash'],
            ]))
            ->assertOk()
            ->assertJsonPath('replaced', 1);

        $this->assertSame('vivienda', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_case_sensitive_preview_only_matches_the_exact_case(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->sceneFor($novel, 'casa Casa', 'Uno');
        $chapterId = $novel->chapters()->value('id');

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload([$chapterId], [
                'search' => 'Casa',
                'case_sensitive' => true,
            ]))
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_preview_is_scoped_to_the_selected_chapters(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->sceneFor($novel, 'casa', 'Uno');
        $this->sceneFor($novel, 'casa', 'Dos');
        $firstId = $novel->chapters()->orderBy('position')->value('id');

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/replace/preview", $this->payload([$firstId]))
            ->assertOk()
            ->assertJsonPath('count', 1);
    }
}
