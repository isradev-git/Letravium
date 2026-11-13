<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class NotesWebTest extends TestCase
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

    private function noteFor(Universe $universe, User $author, array $overrides = []): Note
    {
        return Note::create($overrides + [
            'universe_id' => $universe->id,
            'author_id' => $author->id,
            'title' => 'Investigación',
            'body' => 'Contenido privado',
            'private' => true,
        ]);
    }

    public function test_pages_list_only_the_author_notes_and_hide_foreign_universes(): void
    {
        $owner = $this->writer();
        $other = $this->writer();
        $universe = $this->universeFor($owner);

        $this->noteFor($universe, $owner, ['title' => 'Faro', 'body' => 'Revisar el faro.']);
        $this->noteFor($universe, $other, ['title' => 'Ajeno', 'body' => 'Secreto de otra persona.']);

        $this->asWriter($owner)
            ->get('/workspace/notes')
            ->assertOk()
            ->assertSee('Notas')
            ->assertSee('Revisar el faro.')
            ->assertDontSee('Secreto de otra persona.');

        $this->asWriter($owner)
            ->get("/workspace/universes/{$universe->id}/notes")
            ->assertOk()
            ->assertSee('Revisar el faro.')
            ->assertDontSee('Secreto de otra persona.')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/notes?format=html"', false);

        $this->asWriter($other)
            ->get('/workspace/notes')
            ->assertOk()
            ->assertSee('Secreto de otra persona.')
            ->assertDontSee('Revisar el faro.');

        $this->asWriter($other)
            ->get("/workspace/universes/{$universe->id}/notes")
            ->assertNotFound();
    }

    public function test_html_create_update_and_pending_redirect_and_persist(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);

        $this->asWriter($owner)
            ->post("/workspace/universes/{$universe->id}/notes?format=html", [
                'title' => 'Nueva nota',
                'body' => 'Primera versión.',
                'private' => '1',
                'pending' => '1',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/notes")
            ->assertSessionHas('status');

        $note = Note::where('title', 'Nueva nota')->firstOrFail();
        $this->assertSame($owner->id, $note->author_id);
        $this->assertSame('Primera versión.', $note->body);
        $this->assertTrue($note->pending);

        $this->asWriter($owner)
            ->patch("/workspace/notes/{$note->id}?format=html", [
                'title' => 'Nueva nota',
                'body' => 'Segunda versión.',
                'private' => '0',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/notes")
            ->assertSessionHas('status');

        $note->refresh();
        $this->assertSame('Segunda versión.', $note->body);
        $this->assertFalse($note->private);

        $this->asWriter($owner)
            ->patch("/workspace/notes/{$note->id}/pending?format=html", ['pending' => '0'])
            ->assertRedirect("/workspace/universes/{$universe->id}/notes")
            ->assertSessionHas('status');

        $note->refresh();
        $this->assertFalse($note->pending);
        $this->assertNotNull($note->resolved_at);
    }

    public function test_foreign_note_mutations_are_not_found(): void
    {
        $owner = $this->writer();
        $other = $this->writer();
        $note = $this->noteFor($this->universeFor($other), $other);

        $this->asWriter($owner)
            ->patch("/workspace/notes/{$note->id}?format=html", ['body' => 'Robado'])
            ->assertNotFound();

        $this->asWriter($owner)
            ->patch("/workspace/notes/{$note->id}/pending?format=html", ['pending' => false])
            ->assertNotFound();

        $this->assertSame('Contenido privado', $note->refresh()->body);
    }

    public function test_pendings_page_scopes_to_the_novel_and_hides_foreign_novels(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);
        $novel = $this->novelFor($owner, $universe, 'La carta');
        $otherNovel = $this->novelFor($owner, $universe, 'Otro libro');

        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'is_active' => true, 'alias' => 'A']);
        $scene = Scene::create(['chapter_alternative_id' => $alternative->id, 'position' => 1]);

        $linked = $this->noteFor($universe, $owner, [
            'title' => 'Faro',
            'body' => 'Revisar el faro',
            'pending' => true,
        ]);
        NoteLink::create(['note_id' => $linked->id, 'linkable_type' => 'scene', 'linkable_id' => $scene->id]);

        $this->noteFor($universe, $owner, [
            'title' => 'Otro libro',
            'body' => 'Pendiente de otro libro',
            'novel_id' => $otherNovel->id,
            'pending' => true,
        ]);
        $this->noteFor($universe, $owner, [
            'title' => 'Suelto',
            'body' => 'Pendiente suelto',
            'pending' => true,
        ]);

        $this->asWriter($owner)
            ->get("/workspace/novels/{$novel->id}/pendings")
            ->assertOk()
            ->assertSee('Pendientes')
            ->assertSee('Revisar el faro')
            ->assertDontSee('Pendiente de otro libro')
            ->assertDontSee('Pendiente suelto');

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/pendings")
            ->assertNotFound();
    }

    public function test_json_contracts_remain_json(): void
    {
        $owner = $this->writer();
        $universe = $this->universeFor($owner);

        $note = $this->asWriter($owner)
            ->postJson("/workspace/universes/{$universe->id}/notes", [
                'title' => 'Json',
                'body' => 'Cuerpo',
                'pending' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('note.title', 'Json')
            ->json('note');

        $this->asWriter($owner)
            ->getJson('/workspace/notes')
            ->assertOk()
            ->assertJsonCount(1, 'notes');

        $this->asWriter($owner)
            ->getJson("/workspace/universes/{$universe->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'notes');

        $this->asWriter($owner)
            ->patchJson("/workspace/notes/{$note['id']}", ['body' => 'Actualizado'])
            ->assertOk()
            ->assertJsonPath('note.body', 'Actualizado');

        $this->asWriter($owner)
            ->patchJson("/workspace/notes/{$note['id']}/pending", ['pending' => false])
            ->assertOk()
            ->assertJsonPath('note.pending', false);
    }
}
