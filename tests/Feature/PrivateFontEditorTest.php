<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\Font;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Style;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class PrivateFontEditorTest extends TestCase
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

    private function sceneFor(Novel $novel): Scene
    {
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
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
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Hola mundo']],
            ],
        ]);
    }

    private function fontFor($user): Font
    {
        Storage::disk('local')->put('fonts/owner/font.ttf', "\x00\x01\x00\x00".str_repeat('A', 24));

        return Font::create([
            'owner_id' => $user->id,
            'family' => 'Privada',
            'variant' => 'regular',
            'path' => 'fonts/owner/font.ttf',
            'bytes' => 28,
            'license' => 'SIL OFL',
            'metadata' => ['format' => 'ttf'],
        ]);
    }

    public function test_private_font_is_loaded_in_the_editor_and_can_format_text(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);
        $font = $this->fontFor($user);
        Style::create([
            'novel_id' => $novel->id,
            'name' => 'Cuerpo',
            'kind' => 'paragraph',
            'definition' => ['font_id' => $font->id],
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('@font-face', false)
            ->assertSee("/workspace/fonts/{$font->id}/view", false)
            ->assertSee("font:{$font->id}", false);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'Hola mundo',
                        'font_family' => "font:{$font->id}",
                        'marks' => [[
                            'type' => 'font_family',
                            'start' => 0,
                            'end' => 4,
                            'attrs' => ['family' => "font:{$font->id}"],
                        ]],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scene.content.blocks.0.font_family', "font:{$font->id}");

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-font-family="font:'.$font->id.'"', false)
            ->assertSee('data-value="font:'.$font->id.'"', false)
            ->assertSee("AppFont{$font->id}", false);
    }

    public function test_private_font_file_is_served_to_the_owner_and_authorized_reader_only(): void
    {
        Storage::fake('local');
        $owner = $this->writer();
        $reader = $this->writer();
        $stranger = $this->writer();
        $novel = $this->novelFor($owner);
        $font = $this->fontFor($owner);
        Style::create([
            'novel_id' => $novel->id,
            'name' => 'Cuerpo',
            'kind' => 'paragraph',
            'definition' => ['font_id' => $font->id],
        ]);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        CreativePermission::create([
            'user_id' => $reader->id,
            'resource_type' => 'chapter',
            'resource_id' => $chapter->id,
            'scope' => 'read',
            'granted_by' => $owner->id,
        ]);

        $this->asWriter($owner)
            ->get("/workspace/fonts/{$font->id}/view")
            ->assertOk()
            ->assertHeader('Content-Type', 'font/ttf');

        $this->asWriter($reader)
            ->get("/workspace/fonts/{$font->id}/view")
            ->assertOk();

        $this->asWriter($stranger)
            ->get("/workspace/fonts/{$font->id}/view")
            ->assertNotFound();
    }

    public function test_invalid_private_font_reference_is_rejected(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->sceneFor($novel);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'Hola',
                        'font_family' => 'font:0',
                    ]],
                ],
            ])
            ->assertStatus(422);
    }
}
