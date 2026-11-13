<?php

namespace Tests\Feature;

use App\Models\BibliographyEntry;
use App\Models\Note;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BibliographyWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user, string $name = 'Arven'): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => $name]);
    }

    private function novelFor(User $user, Universe $universe, string $title = 'La carta'): Novel
    {
        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function entryFor(Universe $universe, User $author, array $overrides = []): BibliographyEntry
    {
        return BibliographyEntry::create($overrides + [
            'universe_id' => $universe->id,
            'author_id' => $author->id,
            'author' => 'Autora',
            'title' => 'Historia de Arven',
            'date' => '2020',
            'publisher' => 'Editorial',
        ]);
    }

    private function noteFor(Universe $universe, User $author, array $overrides = []): Note
    {
        return Note::create($overrides + [
            'universe_id' => $universe->id,
            'author_id' => $author->id,
            'title' => 'Fuente privada',
            'body' => 'Nota de investigación',
            'private' => true,
        ]);
    }

    public function test_page_lists_owner_entries_and_hides_foreign_universes(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);
        $novel = $this->novelFor($owner, $universe);

        $this->entryFor($universe, $owner, ['novel_id' => $novel->id]);
        $this->entryFor($universe, $this->writer(), ['title' => 'Entrada ajena']);

        $this->asWriter($owner)
            ->get("/workspace/universes/{$universe->id}/bibliography")
            ->assertOk()
            ->assertSee('Bibliografía')
            ->assertSee('Historia de Arven')
            ->assertSee('Autora')
            ->assertSee('Editorial')
            ->assertDontSee('Entrada ajena')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/bibliography?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/bibliography")
            ->assertNotFound();
    }

    public function test_html_create_select_and_attach_redirect_and_persist(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);
        $novel = $this->novelFor($owner, $universe);

        $this->asWriter($owner)
            ->post("/workspace/universes/{$universe->id}/bibliography?format=html", [
                'novel_id' => $novel->id,
                'author' => 'Autora',
                'title' => 'Historia de Arven',
                'date' => '2020',
                'publisher' => 'Editorial',
                'url' => 'https://example.test/fuente',
                'observations' => 'Fuente principal.',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/bibliography")
            ->assertSessionHas('status');

        $entry = BibliographyEntry::where('title', 'Historia de Arven')->firstOrFail();
        $this->assertSame($owner->id, $entry->author_id);
        $this->assertSame($novel->id, $entry->novel_id);
        $this->assertFalse($entry->selected);

        $this->asWriter($owner)
            ->patch("/workspace/bibliography/{$entry->id}/selected?format=html", ['selected' => '1'])
            ->assertRedirect("/workspace/universes/{$universe->id}/bibliography")
            ->assertSessionHas('status');

        $this->assertTrue($entry->refresh()->selected);

        $note = $this->noteFor($universe, $owner);

        $this->asWriter($owner)
            ->post("/workspace/bibliography/{$entry->id}/notes/{$note->id}?format=html")
            ->assertRedirect("/workspace/universes/{$universe->id}/bibliography")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('bibliography_entry_note', [
            'bibliography_entry_id' => $entry->id,
            'note_id' => $note->id,
        ]);
    }

    public function test_foreign_entry_note_and_novel_are_rejected(): void
    {
        $owner = $this->writer();
        $other = $this->writer();
        $universe = $this->universeFor($owner);
        $entry = $this->entryFor($universe, $owner);
        $foreignEntry = $this->entryFor($this->universeFor($other), $other);
        $foreignNote = $this->noteFor($this->universeFor($other), $other);
        $foreignNovel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $other->id,
            'title' => 'Ajeno',
        ]);

        $this->asWriter($owner)
            ->patch("/workspace/bibliography/{$foreignEntry->id}/selected?format=html", ['selected' => true])
            ->assertNotFound();

        $this->asWriter($owner)
            ->post("/workspace/bibliography/{$entry->id}/notes/{$foreignNote->id}?format=html")
            ->assertNotFound();

        $this->asWriter($owner)
            ->post("/workspace/universes/{$universe->id}/bibliography?format=html", [
                'novel_id' => $foreignNovel->id,
                'title' => 'Entrada con novela ajena',
            ])
            ->assertStatus(422);

        $this->assertSame(1, BibliographyEntry::where('universe_id', $universe->id)->count());
        $this->assertSame(0, $entry->notes()->count());
    }

    public function test_json_contracts_remain_json(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);
        $novel = $this->novelFor($owner, $universe);

        $entry = $this->asWriter($owner)
            ->postJson("/workspace/universes/{$universe->id}/bibliography", [
                'novel_id' => $novel->id,
                'author' => 'Autora',
                'title' => 'Historia de Arven',
            ])
            ->assertCreated()
            ->assertJsonPath('entry.title', 'Historia de Arven')
            ->json('entry');

        $this->asWriter($owner)
            ->getJson("/workspace/universes/{$universe->id}/bibliography")
            ->assertOk()
            ->assertJsonCount(1, 'entries');

        $this->asWriter($owner)
            ->patchJson("/workspace/bibliography/{$entry['id']}/selected", ['selected' => true])
            ->assertOk()
            ->assertJsonPath('entry.selected', true);

        $note = $this->noteFor($universe, $owner);

        $this->asWriter($owner)
            ->postJson("/workspace/bibliography/{$entry['id']}/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('entry.notes.0.id', $note->id);
    }
}
