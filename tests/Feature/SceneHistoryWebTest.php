<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SceneHistoryWebTest extends TestCase
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

    public function test_page_lists_revisions_with_split_and_merge_forms(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user, ['A', 'B']);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $this->document(['A', 'B guardado']),
            ])
            ->assertOk();

        $this->asWriter($user)
            ->get("/workspace/scenes/{$scene->id}/revisions")
            ->assertOk()
            ->assertSee('Historial de la escena')
            ->assertSee('A')
            ->assertSee('B')
            ->assertSee('Revisión 1')
            ->assertSee('action="/workspace/scenes/'.$scene->id.'/split?format=html"', false)
            ->assertSee('action="/workspace/scenes/'.$scene->id.'/merge?format=html"', false)
            ->assertSee('name="base_revision" value="2"', false);
    }

    public function test_foreign_scene_history_and_actions_are_hidden(): void
    {
        $owner = $this->writer();
        [, , $alternative, $scene] = $this->sceneFor($owner, ['A', 'B']);
        $scene->refresh();

        $foreign = $this->writer();

        $this->asWriter($foreign)
            ->get("/workspace/scenes/{$scene->id}/revisions")
            ->assertNotFound();

        $this->asWriter($foreign)
            ->post("/workspace/scenes/{$scene->id}/split?format=html", [
                'base_revision' => 1,
                'block_index' => 1,
            ])
            ->assertNotFound();

        $this->asWriter($foreign)
            ->post("/workspace/scenes/{$scene->id}/merge?format=html", ['base_revision' => 1])
            ->assertNotFound();

        $this->assertSame(1, $alternative->scenes()->count());
    }

    public function test_html_split_redirects_and_creates_the_second_scene(): void
    {
        $user = $this->writer();
        [, , $alternative, $scene] = $this->sceneFor($user, ['A', 'B', 'C', 'D']);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/split?format=html", [
                'base_revision' => 1,
                'block_index' => 2,
                'title' => 'Segunda mitad',
            ])
            ->assertRedirect("/workspace/scenes/{$scene->id}/revisions")
            ->assertSessionHas('status');

        $created = Scene::where('chapter_alternative_id', $alternative->id)
            ->where('position', 1)
            ->firstOrFail();

        $this->assertSame(['C', 'D'], array_column($created->content['blocks'], 'text'));
        $this->assertSame('Segunda mitad', $created->title);
        $this->assertSame(['A', 'B'], array_column($scene->refresh()->content['blocks'], 'text'));
    }

    public function test_html_merge_redirects_and_merges_the_next_scene(): void
    {
        $user = $this->writer();
        [, , $alternative, $first] = $this->sceneFor($user, ['A', 'B']);
        $second = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'position' => 1,
            'content' => $this->document(['C', 'D']),
        ]);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$first->id}/merge?format=html", ['base_revision' => 1])
            ->assertRedirect("/workspace/scenes/{$first->id}/revisions")
            ->assertSessionHas('status');

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($first->refresh()->content['blocks'], 'text'));
        $this->assertNull(Scene::find($second->id));
    }

    public function test_html_split_and_merge_keep_the_stale_revision_conflict(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user, ['A', 'B']);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/split?format=html", [
                'base_revision' => 9,
                'block_index' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('current_revision', 1);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/merge?format=html", ['base_revision' => 9])
            ->assertStatus(409)
            ->assertJsonPath('current_revision', 1);

        $this->assertSame(1, Scene::where('chapter_alternative_id', $scene->chapter_alternative_id)->count());
    }

    public function test_json_history_and_split_contracts_are_unchanged(): void
    {
        $user = $this->writer();
        [, , , $scene] = $this->sceneFor($user, ['A', 'B']);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $this->document(['A', 'B guardado']),
            ])
            ->assertOk();

        $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene->id}/revisions")
            ->assertOk()
            ->assertJsonStructure(['revisions', 'next_cursor', 'has_more'])
            ->assertJsonPath('revisions.0.revision', 1);

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/split", [
                'base_revision' => 2,
                'block_index' => 1,
            ])
            ->assertOk()
            ->assertJsonStructure(['scene', 'created_scene']);
    }
}
