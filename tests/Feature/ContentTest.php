<?php

namespace Tests\Feature;

use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ContentTest extends TestCase
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

    private function validContent(string $text = 'El puente de piedra.'): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
        ];
    }

    public function test_chapter_has_one_active_alternative_and_switching_preserves_others(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->assertCreated()
            ->json('chapter');

        $first = $chapter['alternatives'][0];
        $this->assertTrue($first['is_active']);

        $second = $this->asWriter($user)
            ->postJson("/workspace/chapters/{$chapter['id']}/alternatives", [
                'alias' => 'Final alternativo',
                'active' => true,
            ])
            ->assertCreated()
            ->json('alternative');

        $this->assertFalse(ChapterAlternative::findOrFail($first['id'])->is_active);
        $this->assertTrue($second['is_active']);

        $this->asWriter($user)
            ->patchJson("/workspace/alternatives/{$first['id']}/activate")
            ->assertOk();

        $this->assertTrue(ChapterAlternative::findOrFail($first['id'])->is_active);
        $this->assertFalse(ChapterAlternative::findOrFail($second['id'])->is_active);
    }

    public function test_scene_update_rejects_null_content_and_records_a_revision(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');
        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'content' => $this->validContent('Original'),
            ])
            ->assertCreated()
            ->json('scene');

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => null])
            ->assertStatus(422);

        $this->assertSame('Original', $scene['content']['blocks'][0]['text']);

        $updated = $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $this->validContent('Editado')])
            ->assertOk()
            ->json('scene');

        $this->assertSame('Editado', $updated['content']['blocks'][0]['text']);
        $this->assertSame(2, $updated['revision']);
        $this->assertDatabaseHas('content_revisions', ['scene_id' => $scene['id'], 'revision' => 1]);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", [
                'base_revision' => 1,
                'content' => $this->validContent('Obsoleto'),
            ])
            ->assertStatus(409);
    }

    public function test_content_schema_rejects_unknown_version_and_active_nodes(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');
        $alternative = $chapter['alternatives'][0];

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$alternative['id']}/scenes", [
                'content' => ['schema_version' => 2, 'blocks' => []],
            ])->assertStatus(422);

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$alternative['id']}/scenes", [
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [['id' => 'x', 'type' => 'script', 'text' => 'alert(1)']],
                ],
            ])->assertStatus(422);
    }

    public function test_word_count_uses_active_prose_once_and_excludes_inactive_alternatives_and_plans(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');
        $active = $chapter['alternatives'][0];

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$active['id']}/scenes", [
                'content' => $this->validContent('teórico-práctico 2026 …'),
            ])->assertCreated();

        $inactive = $this->asWriter($user)
            ->postJson("/workspace/chapters/{$chapter['id']}/alternatives", [
                'alias' => 'Otra',
                'active' => false,
            ])->json('alternative');

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$inactive['id']}/scenes", [
                'content' => $this->validContent(str_repeat('palabra ', 100)),
            ])->assertCreated();

        $this->asWriter($user)
            ->putJson("/workspace/chapters/{$chapter['id']}/plan", [
                'plan' => ['summary' => 'Resumen privado que no cuenta'],
            ])->assertOk();

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/word-count")
            ->assertOk()
            ->assertJson(['total' => 2, 'target' => null]);
    }

    public function test_work_state_rename_preserves_assignment(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');
        $alternative = $chapter['alternatives'][0];

        $state = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/work-states", ['name' => 'Escribiendo'])
            ->assertCreated()
            ->json('work_state');

        $this->asWriter($user)
            ->putJson("/workspace/alternatives/{$alternative['id']}/work-state", [
                'work_state_id' => $state['id'],
            ])->assertOk();

        $this->asWriter($user)
            ->patchJson("/workspace/work-states/{$state['id']}", ['name' => 'Revisar diálogos'])
            ->assertOk()
            ->assertJsonPath('work_state.name', 'Revisar diálogos');

        $this->assertSame(
            $state['id'],
            (int) DB::table('chapter_alternatives')->where('id', $alternative['id'])->value('work_state_id'),
        );
    }

    public function test_plot_links_a_passage_and_reordering_changes_only_reading_order(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $first = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');
        $second = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Dos'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$first['alternatives'][0]['id']}/scenes", [
                'content' => $this->validContent(),
            ])->json('scene');

        $plot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/plots", ['name' => 'La carta'])
            ->assertCreated()
            ->json('plot');

        $this->asWriter($user)
            ->postJson("/workspace/plots/{$plot['id']}/scenes/{$scene['id']}")
            ->assertOk();

        $this->assertDatabaseHas('plot_scene', ['plot_id' => $plot['id'], 'scene_id' => $scene['id']]);

        $this->asWriter($user)
            ->patchJson("/workspace/novels/{$novel->id}/chapters/order", [
                'order' => [$second['id'], $first['id']],
            ])->assertOk();

        $this->assertSame(
            [0, 1],
            DB::table('chapters')->whereIn('id', [$second['id'], $first['id']])
                ->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all(),
        );
    }

    public function test_manuscript_and_chapter_views_share_the_same_canonical_scenes(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $sceneIds = [];

        for ($i = 1; $i <= 3; $i++) {
            $chapter = $this->asWriter($user)
                ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => "Capítulo {$i}"])
                ->assertCreated()
                ->json('chapter');

            $scene = $this->asWriter($user)
                ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                    'content' => $this->validContent("Texto {$i}"),
                ])->assertCreated()->json('scene');

            $sceneIds[] = $scene['id'];
        }

        $page1 = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/manuscript?limit=2")
            ->assertOk()
            ->json();

        $this->assertCount(2, $page1['chapters']);
        $this->assertTrue($page1['has_more']);
        $this->assertSame([1, 2], array_column($page1['chapters'], 'position'));
        $this->assertSame(3, $page1['next_cursor']);

        $page2 = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/manuscript?cursor={$page1['next_cursor']}&limit=2")
            ->assertOk()
            ->json();

        $this->assertCount(1, $page2['chapters']);
        $this->assertFalse($page2['has_more']);
        $this->assertNull($page2['next_cursor']);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$sceneIds[0]}", ['content' => $this->validContent('Texto editado')])
            ->assertOk();

        $chapterView = $this->asWriter($user)
            ->getJson("/workspace/chapters/{$page1['chapters'][0]['id']}")
            ->assertOk()
            ->json('chapter');

        $this->assertSame('Texto editado', $chapterView['scenes'][0]['content']['blocks'][0]['text']);
        $this->assertSame($page1['chapters'][0]['id'], $chapterView['id']);
        $this->assertSame($page1['chapters'][0]['position'], $chapterView['position']);

        $manuscript = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/manuscript?limit=10")
            ->assertOk()
            ->json();

        $this->assertCount(3, $manuscript['chapters']);
        $this->assertSame('Texto editado', $manuscript['chapters'][0]['scenes'][0]['content']['blocks'][0]['text']);
        $this->assertSame($page1['chapters'][0]['id'], $manuscript['chapters'][0]['id']);
        $this->assertSame($page1['chapters'][0]['position'], $manuscript['chapters'][0]['position']);

        $other = $this->writer();
        $this->asWriter($other)
            ->getJson("/workspace/novels/{$novel->id}/manuscript")
            ->assertNotFound();
    }
}
