<?php

namespace Tests\Feature;

use App\Models\BookMark;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BookNavigationWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'h1', 'type' => 'heading', 'level' => 2, 'text' => 'Primera parte']],
            ],
        ]);

        return $novel;
    }

    public function test_owner_sees_navigation_and_marks(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        BookMark::create(['novel_id' => $novel->id, 'type' => 'figure', 'label' => 'Mapa', 'position' => 0]);
        BookMark::create(['novel_id' => $novel->id, 'type' => 'table', 'label' => 'Tabla 1', 'position' => 1]);
        BookMark::create(['novel_id' => $novel->id, 'type' => 'index', 'label' => 'Árbol', 'position' => 2]);
        BookMark::create(['novel_id' => $novel->id, 'type' => 'glossary', 'label' => 'Bruma', 'position' => 3]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book/navigation")
            ->assertOk()
            ->assertSee('Índices y navegación')
            ->assertSee('Uno')
            ->assertSee('Primera parte')
            ->assertSee('Mapa')
            ->assertSee('Tabla 1')
            ->assertSee('Árbol')
            ->assertSee('Bruma')
            ->assertSee('name="type"', false)
            ->assertSee('name="label"', false);
    }

    public function test_owner_can_create_update_and_delete_marks(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/book/marks", [
                'type' => 'figure',
                'label' => 'Mapa',
                'caption' => 'Mapa del faro.',
                'target_type' => 'scene',
                'target_id' => 1,
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/navigation");

        $mark = BookMark::where('novel_id', $novel->id)->firstOrFail();
        $this->assertSame('Mapa', $mark->label);

        $this->asWriter($user)
            ->patch("/workspace/book/marks/{$mark->id}", [
                'label' => 'Mapa revisado',
                'caption' => 'Mapa actualizado.',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/navigation");

        $this->assertSame('Mapa revisado', $mark->fresh()->label);

        $this->asWriter($user)
            ->delete("/workspace/book/marks/{$mark->id}")
            ->assertRedirect("/workspace/novels/{$novel->id}/book/navigation");

        $this->assertDatabaseMissing('book_marks', ['id' => $mark->id]);
    }

    public function test_other_accounts_cannot_open_or_change_navigation(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/novels/{$novel->id}/book/navigation")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->post("/workspace/novels/{$novel->id}/book/marks", ['type' => 'figure', 'label' => 'Ajena'])
            ->assertNotFound();
    }
}
