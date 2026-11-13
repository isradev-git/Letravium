<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceVisualTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user, string $name = 'Arven'): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => $name]);
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
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'El faro.']],
            ],
        ]);

        return $novel;
    }

    public function test_private_library_shows_the_shared_navigation(): void
    {
        $user = $this->writer();

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSee('Biblioteca')
            ->assertSee('/account/sessions', false)
            ->assertSee('workspace-page', false);
    }

    public function test_editor_shows_navigation_context_and_panel_controls(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSee('Universo')
            ->assertSee('Planificar')
            ->assertSee('Escribir')
            ->assertSee('Revisar')
            ->assertSee('Versiones')
            ->assertSee('Libro')
            ->assertSee('Cuenta')
            ->assertSee('aria-label="Contexto"', false)
            ->assertSee('Arven')
            ->assertSee('Capítulos')
            ->assertSee('data-workspace-nav-toggle', false)
            ->assertDontSee('id="toggle-nav"', false)
            ->assertSee('id="toggle-chapters"', false)
            ->assertSee('id="toggle-context"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('Guardado en servidor');
    }

    public function test_owner_only_navigation_is_hidden_from_a_collaborator(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $collaborator = $this->writer();
        CreativePermission::create([
            'user_id' => $collaborator->id,
            'resource_type' => 'novel',
            'resource_id' => $novel->id,
            'scope' => 'read',
            'granted_by' => $owner->id,
        ]);

        $this->asWriter($collaborator)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('Escribir')
            ->assertDontSee('Planificar')
            ->assertDontSee('/workspace/novels/'.$novel->id.'/book', false)
            ->assertDontSee('/workspace/novels/'.$novel->id.'/snapshots', false);
    }
}
