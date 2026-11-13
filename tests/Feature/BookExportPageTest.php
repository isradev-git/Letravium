<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Footnote;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BookExportPageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novel($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);

        return $novel;
    }

    public function test_owner_sees_preflight_and_export_formats(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);
        BookProfile::create(['novel_id' => $novel->id, 'name' => 'A5 de prueba']);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book")
            ->assertOk()
            ->assertSee('Libro y exportación')
            ->assertSee('Comprobación previa')
            ->assertSee('A5 de prueba')
            ->assertSee('value="txt"', false)
            ->assertSee('value="docx"', false)
            ->assertSee('value="epub"', false)
            ->assertSee('value="pdf"', false)
            ->assertSee('Preparar exportación')
            ->assertSee('/workspace/novels/'.$novel->id.'/export-manifests', false);
    }

    public function test_missing_profile_is_reported_as_a_warning(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book")
            ->assertOk()
            ->assertSee('No hay perfil editorial');
    }

    public function test_blocking_preflight_errors_are_shown(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);
        Footnote::create(['novel_id' => $novel->id, 'kind' => 'footnote', 'number' => 1, 'text' => '']);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book")
            ->assertOk()
            ->assertSee('Corrige los fallos')
            ->assertSee('La nota 1 está vacía.');
    }

    public function test_other_accounts_cannot_open_the_book_screen(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($owner);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/book")
            ->assertNotFound();
    }
}
