<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\CreativePermission;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceShellRedesignTest extends TestCase
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

    public function test_library_uses_the_grouped_workspace_shell_and_explains_the_empty_novel_group(): void
    {
        $this->asWriter($this->writer())
            ->get('/workspace')
            ->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSee('data-nav-group="library"', false)
            ->assertSee('data-nav-group="novel"', false)
            ->assertSee('data-nav-group="archive"', false)
            ->assertSee('data-nav-group="account"', false)
            ->assertSee('Abre una novela', false)
            ->assertSee('data-workspace-nav-toggle', false)
            ->assertDontSee('class="site-header"', false);
    }

    public function test_editor_marks_writing_active_and_exposes_the_novel_links(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('>Escribir<', false)
            ->assertSee("/workspace/novels/{$novel->id}/planning", false)
            ->assertSee("/workspace/novels/{$novel->id}/comments", false)
            ->assertSee("/workspace/novels/{$novel->id}/snapshots", false)
            ->assertSee("/workspace/novels/{$novel->id}/book", false)
            ->assertDontSee('Abre una novela', false);
    }

    public function test_snapshot_and_card_detail_pages_keep_their_context(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $snapshot = Snapshot::create([
            'novel_id' => $novel->id,
            'name' => 'Versión uno',
            'manifest' => ['chapters' => []],
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $card = Card::create(['universe_id' => $novel->universe_id, 'area' => 'places', 'name' => 'Faro']);

        $this->asWriter($user)
            ->get("/workspace/snapshots/{$snapshot->id}/compare")
            ->assertOk()
            ->assertSee('data-nav-group="novel"', false)
            ->assertSee('>Versiones</a>', false);

        $this->asWriter($user)
            ->get("/workspace/cards/{$card->id}")
            ->assertOk()
            ->assertSee('>Universos</a>', false);
    }

    public function test_account_marketing_marks_comunicaciones_active(): void
    {
        $this->asWriter($this->writer())
            ->get('/account/marketing')
            ->assertOk()
            ->assertSee('href="/account/marketing" class="workspace-sidebar-link is-active"', false)
            ->assertSee('href="/account/sessions" class="workspace-sidebar-link"', false);
    }

    public function test_collaborator_does_not_get_a_broken_universe_link(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
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
            ->assertSee('data-icon="U">Universos</span>', false)
            ->assertDontSee("/workspace/universes/{$novel->universe_id}/cards", false);
    }

    public function test_all_writing_account_and_book_pages_use_the_shell(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        foreach ([
            "/workspace/novels/{$novel->id}/planning",
            "/workspace/novels/{$novel->id}/snapshots",
            "/workspace/novels/{$novel->id}/book",
            '/workspace/imports',
            '/workspace/backups',
            '/account/sessions',
            '/account/close',
        ] as $path) {
            $this->asWriter($user)
                ->get($path)
                ->assertOk()
                ->assertSee('data-workspace-shell', false)
                ->assertSee('data-nav-group="library"', false)
                ->assertSee('data-nav-group="archive"', false)
                ->assertSee('data-nav-group="account"', false);
        }
    }

    public function test_universe_page_marks_universe_active_without_enabling_novel_actions(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/documents")
            ->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSee('data-nav-group="universe"', false)
            ->assertSee('Abre una novela', false)
            ->assertSee('Universos', false);
    }
}
