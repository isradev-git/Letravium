<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceLibraryRedesignTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_library_uses_dialogs_instead_of_the_global_button_row(): void
    {
        $this->asWriter($this->writer())
            ->get('/workspace')
            ->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSee('library-hero', false)
            ->assertSee('data-dialog-open="dialog-new-novel"', false)
            ->assertSee('data-dialog-open="dialog-new-universe"', false)
            ->assertSee('data-dialog-open="dialog-new-collection"', false)
            ->assertSee('id="dialog-new-novel"', false)
            ->assertSee('id="dialog-new-universe"', false)
            ->assertSee('id="dialog-new-collection"', false)
            ->assertDontSee('class="library-actions"', false)
            ->assertSee('data-dialog-safe', false);
    }

    public function test_library_renders_item_dialogs_with_the_existing_routes(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $collection = Collection::create(['owner_id' => $user->id, 'name' => 'Saga']);
        $collection->novels()->attach($novel->id, ['position' => 0]);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('id="dialog-edit-universe-'.$universe->id.'"', false)
            ->assertSee('id="dialog-rename-novel-'.$novel->id.'"', false)
            ->assertSee('id="dialog-duplicate-novel-'.$novel->id.'"', false)
            ->assertSee('id="dialog-archive-novel-'.$novel->id.'"', false)
            ->assertSee('id="dialog-rename-collection-'.$collection->id.'"', false)
            ->assertSee('id="dialog-delete-collection-'.$collection->id.'"', false)
            ->assertSee('action="/workspace/novels/'.$novel->id.'/archive?format=html"', false)
            ->assertSee('action="/workspace/collections/'.$collection->id.'?format=html"', false);
    }

    public function test_new_novel_dialog_selects_the_universe_and_keeps_the_form_action(): void
    {
        $user = $this->writer();
        $first = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        Universe::create(['owner_id' => $user->id, 'name' => 'Bruma']);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('data-novel-form', false)
            ->assertSee('data-novel-universe', false)
            ->assertSee('/workspace/universes/'.$first->id.'/novels', false)
            ->assertSee('name="universe_id"', false);
    }
}
