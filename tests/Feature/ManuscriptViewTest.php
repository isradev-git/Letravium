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

class ManuscriptViewTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelWithChapter($user, string $chapterTitle, string $body, int $position = 1): array
    {
        $universe = Universe::firstOrCreate(
            ['owner_id' => $user->id, 'name' => 'Arven'],
        );
        $novel = Novel::firstOrCreate(
            ['owner_id' => $user->id, 'title' => 'La carta'],
            ['universe_id' => $universe->id],
        );
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => $chapterTitle,
            'position' => $position,
        ]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => $chapterTitle.' escena',
            'position' => 1,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b'.$chapter->id, 'type' => 'paragraph', 'text' => $body, 'marks' => []]],
            ],
        ]);

        return [$novel, $chapter, $scene];
    }

    public function test_chapter_view_renders_only_the_selected_chapter_body(): void
    {
        $user = $this->writer();
        [$novel, $chapterOne] = $this->novelWithChapter($user, 'Uno', 'Cuerpo del primer capítulo', 1);
        [, , $sceneTwo] = $this->novelWithChapter($user, 'Dos', 'Cuerpo del segundo capítulo', 2);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}?view=chapter&chapter={$chapterOne->id}")
            ->assertOk()
            ->assertSee('Cuerpo del primer capítulo')
            ->assertDontSee('Cuerpo del segundo capítulo')
            ->assertDontSee('data-scene-id="'.$sceneTwo->id.'"', false)
            ->assertSee('Manuscrito completo');
    }

    public function test_manuscript_view_pages_chapters_and_links_the_next_page(): void
    {
        $user = $this->writer();

        for ($i = 1; $i <= 12; $i++) {
            [$novel] = $this->novelWithChapter($user, "Capítulo {$i}", "Cuerpo número {$i}", $i);
        }

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('Cuerpo número 1')
            ->assertSee('Cuerpo número 10')
            ->assertDontSee('Cuerpo número 11')
            ->assertSee('Cargar más capítulos')
            ->assertSee('cursor=11', false);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}?view=manuscript&cursor=11")
            ->assertOk()
            ->assertSee('Cuerpo número 11')
            ->assertSee('Cuerpo número 12')
            ->assertDontSee('Cargar más capítulos');
    }

    public function test_both_views_expose_the_same_canonical_scene_editor(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene] = $this->novelWithChapter($user, 'Uno', 'Texto canónico', 1);

        foreach ([
            "/workspace/novels/{$novel->id}?view=chapter&chapter={$chapter->id}",
            "/workspace/novels/{$novel->id}?view=manuscript",
        ] as $url) {
            $this->asWriter($user)
                ->get($url)
                ->assertOk()
                ->assertSee('data-scene-id="'.$scene->id.'"', false)
                ->assertSee('contenteditable="true"', false)
                ->assertSee('Texto canónico');
        }
    }

    public function test_chapter_view_rejects_a_chapter_from_another_novel(): void
    {
        $user = $this->writer();
        [$novel] = $this->novelWithChapter($user, 'Uno', 'Texto', 1);
        [, $foreignChapter] = $this->novelWithChapter($this->writer(), 'Ajena', 'Texto ajeno', 1);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}?view=chapter&chapter={$foreignChapter->id}")
            ->assertNotFound();
    }
}
