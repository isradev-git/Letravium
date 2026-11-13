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

class RunFormattingTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function scene($user): Scene
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 1,
            'revision' => 1,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Hola mundo', 'marks' => []]]],
        ]);
    }

    public function test_run_font_family_and_size_survive_save_and_validation(): void
    {
        $user = $this->writer();
        $scene = $this->scene($user);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'Hola mundo',
                        'marks' => [
                            ['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => ['family' => 'serif']],
                            ['type' => 'font_size', 'start' => 5, 'end' => 10, 'attrs' => ['size' => 18]],
                        ],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scene.content.blocks.0.marks.0.attrs.family', 'serif')
            ->assertJsonPath('scene.content.blocks.0.marks.1.attrs.size', 18);
    }

    public function test_invalid_run_formatting_is_rejected(): void
    {
        $user = $this->writer();
        $scene = $this->scene($user);

        $cases = [
            [['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => ['family' => 'comic']]],
            [['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => []]],
            [['type' => 'font_size', 'start' => 0, 'end' => 4, 'attrs' => ['size' => 100]]],
            [['type' => 'font_size', 'start' => 0, 'end' => 4, 'attrs' => ['size' => '18']]],
            [['type' => 'font_size', 'start' => 0, 'end' => 4, 'attrs' => []]],
        ];

        foreach ($cases as $index => $marks) {
            $this->asWriter($user)
                ->putJson("/workspace/scenes/{$scene->id}/content", [
                    'base_revision' => 1,
                    'content' => [
                        'schema_version' => 1,
                        'blocks' => [[
                            'id' => 'b'.($index + 1),
                            'type' => 'paragraph',
                            'text' => 'Hola mundo',
                            'marks' => $marks,
                        ]],
                    ],
                ])
                ->assertStatus(422);
        }
    }
}
