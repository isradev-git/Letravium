<?php

namespace Tests\Feature;

use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class EditorPageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novel($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    public function test_owner_opens_the_writing_destination_with_rendered_canonical_content(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'title' => 'Puente',
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'El puente de piedra <ajeno>.',
                        'marks' => [['type' => 'bold', 'start' => 0, 'end' => 2]],
                    ]],
                ],
            ])
            ->json('scene');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('La carta')
            ->assertSee('Uno')
            ->assertSee('Puente')
            ->assertSee('contenteditable="true"', false)
            ->assertSee('data-scene-id="'.$scene['id'].'"', false)
            ->assertSee('<strong>El</strong> puente de piedra &lt;ajeno&gt;.', false)
            ->assertSee('aria-label="Negrita"', false)
            ->assertSee('aria-label="Guardar"', false);
    }

    public function test_editor_title_is_escaped_and_cannot_inject_markup(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => '</title><script>alert(1)</script>',
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_editor_offers_an_accessible_focus_mode_control(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('id="focus-toggle"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertSee('aria-keyshortcuts', false)
            ->assertSee('Solo texto');
    }

    public function test_editor_exposes_run_format_controls_and_renders_run_marks(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'title' => 'Puente',
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'Hola mundo',
                        'marks' => [
                            ['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => ['family' => 'serif']],
                            ['type' => 'font_size', 'start' => 5, 'end' => 10, 'attrs' => ['size' => 18]],
                        ],
                    ]],
                ],
            ])
            ->assertCreated();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('id="format-run-family"', false)
            ->assertSee('id="format-run-size"', false)
            ->assertSee('id="run-apply"', false)
            ->assertSee('id="run-remove"', false)
            ->assertSee('data-mark="font_family"', false)
            ->assertSee('data-value="18"', false);
    }

    public function test_editor_exposes_table_and_image_controls_and_renders_table_cells(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'title' => 'Puente',
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 't1',
                        'type' => 'table',
                        'caption' => 'Cronología',
                        'rows' => [
                            ['id' => 'r1', 'header' => true, 'cells' => [
                                ['id' => 'c1', 'text' => 'Fecha'],
                            ]],
                            ['id' => 'r2', 'cells' => [
                                ['id' => 'c2', 'text' => 'Año 1'],
                            ]],
                        ],
                    ]],
                ],
            ])
            ->assertCreated();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-panel="table"', false)
            ->assertSee('data-panel="image"', false)
            ->assertSee('id="table-panel"', false)
            ->assertSee('id="image-panel"', false)
            ->assertSee('id="table-apply"', false)
            ->assertSee('id="image-apply"', false)
            ->assertSee('<table>', false)
            ->assertSee('<th scope="col">Fecha</th>', false)
            ->assertSee('<td>Año 1</td>', false)
            ->assertSee('<figcaption>Cronología</figcaption>', false);
    }

    public function test_editor_exposes_character_style_controls(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Énfasis',
                'kind' => 'character',
                'definition' => ['italic' => true, 'size' => 14],
            ])
            ->assertCreated();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('id="format-character-style"', false)
            ->assertSee('id="character-apply"', false)
            ->assertSee('id="character-remove"', false)
            ->assertSee('Énfasis');
    }

    public function test_editor_exposes_local_spelling_controls(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-panel="spelling"', false)
            ->assertSee('id="spelling-panel"', false)
            ->assertSee('id="spelling-summary"', false)
            ->assertSee('id="spelling-list"', false)
            ->assertSee('id="spelling-close"', false)
            ->assertSee('id="spelling-popover"', false)
            ->assertSee('id="spelling-popover-word"', false)
            ->assertSee('id="spelling-popover-actions"', false)
            ->assertSee('id="synonym-popover"', false)
            ->assertSee('id="synonym-popover-word"', false)
            ->assertSee('id="synonym-popover-groups"', false)
            ->assertSee('Ortografía');
    }

    public function test_editor_exposes_local_synonym_controls(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-panel="synonyms"', false)
            ->assertSee('id="synonyms-panel"', false)
            ->assertSee('id="synonyms-word"', false)
            ->assertSee('id="synonyms-groups"', false)
            ->assertSee('id="synonyms-close"', false)
            ->assertSee('Sinónimos');
    }

    public function test_editor_exposes_reversible_typography_helpers(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-panel="typography"', false)
            ->assertSee('id="typography-panel"', false)
            ->assertSee('id="typography-dialogue"', false)
            ->assertSee('id="typography-quotes"', false)
            ->assertSee('id="typography-ellipsis"', false)
            ->assertSee('id="typography-nbsp"', false)
            ->assertSee('id="typography-apply"', false)
            ->assertSee('No reescribe frases', false);
    }

    public function test_editor_exposes_note_controls_and_renders_note_anchors(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'title' => 'Puente',
                'content' => ['schema_version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto con nota', 'marks' => []]]],
            ])
            ->assertCreated()
            ->json('scene');

        $note = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'foot',
                'content' => ['schema_version' => 1, 'blocks' => [['id' => 'n1', 'type' => 'paragraph', 'text' => 'Aclaración.', 'marks' => []]]],
                'scene_id' => $scene['id'],
            ])
            ->assertCreated()
            ->json('footnote');

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", [
                'content' => ['schema_version' => 1, 'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Texto con nota',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 10,
                        'end' => 14,
                        'attrs' => ['note_id' => $note['id']],
                    ]],
                ]]],
            ])
            ->assertOk();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('data-panel="note"', false)
            ->assertSee('id="note-panel"', false)
            ->assertSee('id="note-body"', false)
            ->assertSee('id="note-reference"', false)
            ->assertSee('data-mark="footnote_reference"', false)
            ->assertSee('data-note-id="'.$note['id'].'"', false)
            ->assertSee('data-note-number="'.$note['number'].'"', false);
    }

    public function test_other_accounts_cannot_open_the_writing_destination(): void
    {
        $owner = $this->writer();
        $novel = $this->novel($owner);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}")
            ->assertNotFound();
    }

    public function test_owner_without_chapters_sees_a_saved_status_not_read_only(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('Guardado en servidor')
            ->assertDontSee('Solo lectura');
    }

    public function test_empty_novel_offers_a_chapter_creation_form(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}")
            ->assertOk()
            ->assertSee('Todavía no hay capítulos')
            ->assertSee('action="/workspace/novels/'.$novel->id.'/chapters"', false);
    }

    public function test_creating_a_chapter_from_the_form_returns_to_the_editor(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->assertRedirect("/workspace/novels/{$novel->id}");

        $this->assertDatabaseHas('chapters', ['novel_id' => $novel->id, 'title' => 'Uno']);
    }

    public function test_adding_a_scene_from_the_form_returns_to_the_editor(): void
    {
        $user = $this->writer();
        $novel = $this->novel($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $this->asWriter($user)
            ->post("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", ['title' => 'Puente'])
            ->assertRedirect("/workspace/novels/{$novel->id}");

        $this->assertDatabaseHas('scenes', ['title' => 'Puente']);
    }
}
