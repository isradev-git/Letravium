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

class ParagraphFormattingTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function scene($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 1,
            'revision' => 1,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto', 'marks' => []]]],
        ]);

        return [$scene, $alternative];
    }

    public function test_paragraph_formatting_survives_save_and_validation(): void
    {
        $user = $this->writer();
        [$scene] = $this->scene($user);

        $formatting = [
            'align' => 'justify',
            'font_family' => 'serif',
            'font_size' => 22,
            'indent_first' => -1,
            'indent_left' => 2,
            'line_height' => 200,
            'spacing_before' => 12,
            'spacing_after' => 18,
        ];

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [array_merge(['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto', 'marks' => []], $formatting)],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scene.content.blocks.0.align', 'justify')
            ->assertJsonPath('scene.content.blocks.0.font_size', 22)
            ->assertJsonPath('scene.content.blocks.0.spacing_after', 18);
    }

    public function test_invalid_paragraph_formatting_is_rejected(): void
    {
        $user = $this->writer();
        [$scene] = $this->scene($user);
        $revision = 1;

        $cases = [
            ['align' => 'diagonal'],
            ['font_family' => 'comic'],
            ['font_size' => 100],
            ['indent_first' => -9],
            ['indent_left' => 9],
            ['line_height' => 50],
            ['spacing_before' => -1],
            ['spacing_after' => 200],
        ];

        foreach ($cases as $index => $formatting) {
            $this->asWriter($user)
                ->putJson("/workspace/scenes/{$scene->id}/content", [
                    'base_revision' => $revision,
                    'content' => [
                        'schema_version' => 1,
                        'blocks' => [array_merge(
                            ['id' => 'b'.($index + 1), 'type' => 'paragraph', 'text' => 'Texto', 'marks' => []],
                            $formatting,
                        )],
                    ],
                ])
                ->assertStatus(422);
        }

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => $revision,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 's1',
                        'type' => 'scene_break',
                        'align' => 'center',
                    ]],
                ],
            ])
            ->assertStatus(422);
    }
}
