<?php

namespace Tests\Feature;

use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class LibraryMetadataWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function fixture($user): array
    {
        $universe = Universe::create([
            'owner_id' => $user->id,
            'name' => 'Arven',
            'description' => 'Un reino de niebla.',
        ]);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);

        return [$universe, $novel];
    }

    public function test_library_shows_rename_and_universe_edit_controls(): void
    {
        $user = $this->writer();
        [$universe, $novel] = $this->fixture($user);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('action="/workspace/novels/'.$novel->id.'?format=html"', false)
            ->assertSee('action="/workspace/universes/'.$universe->id.'?format=html"', false);
    }

    public function test_novel_can_be_renamed_without_changing_identity(): void
    {
        $user = $this->writer();
        [$universe, $novel] = $this->fixture($user);

        $this->asWriter($user)
            ->patch("/workspace/novels/{$novel->id}?format=html", ['title' => 'La carta del faro'])
            ->assertRedirect('/workspace');

        $novel->refresh();
        $this->assertSame('La carta del faro', $novel->title);
        $this->assertSame($universe->id, $novel->universe_id);
        $this->assertSame($user->id, $novel->owner_id);
    }

    public function test_universe_name_and_description_can_be_edited(): void
    {
        $user = $this->writer();
        [$universe] = $this->fixture($user);

        $this->asWriter($user)
            ->patch("/workspace/universes/{$universe->id}?format=html", [
                'name' => 'Arven del norte',
                'description' => 'Niebla y faros.',
            ])
            ->assertRedirect('/workspace');

        $universe->refresh();
        $this->assertSame('Arven del norte', $universe->name);
        $this->assertSame('Niebla y faros.', $universe->description);
    }

    public function test_foreign_accounts_cannot_edit_and_validation_is_reported(): void
    {
        $owner = $this->writer();
        [$universe, $novel] = $this->fixture($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->patch("/workspace/novels/{$novel->id}", ['title' => 'Robada'])
            ->assertNotFound();
        $this->asWriter($stranger)
            ->patch("/workspace/universes/{$universe->id}?format=html", ['name' => 'Robado'])
            ->assertNotFound();

        $this->asWriter($owner)
            ->patch("/workspace/novels/{$novel->id}", ['title' => ''])
            ->assertSessionHasErrors('title');
        $this->asWriter($owner)
            ->patch("/workspace/universes/{$universe->id}?format=html", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('La carta', $novel->fresh()->title);
        $this->assertSame('Arven', $universe->fresh()->name);
    }

    public function test_json_contracts_for_library_metadata_updates(): void
    {
        $user = $this->writer();
        [$universe, $novel] = $this->fixture($user);

        $this->asWriter($user)
            ->patchJson("/workspace/novels/{$novel->id}", ['title' => 'Otra carta'])
            ->assertOk()
            ->assertJsonPath('novel.title', 'Otra carta')
            ->assertJsonPath('novel.id', $novel->id);

        $this->asWriter($user)
            ->patchJson("/workspace/universes/{$universe->id}", ['name' => 'Otro reino'])
            ->assertOk()
            ->assertJsonPath('universe.name', 'Otro reino');
    }
}
