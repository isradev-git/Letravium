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

class NoteEditorTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function scene($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 1,
            'revision' => 1,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto', 'marks' => []]]],
        ]);

        return [$novel, $scene];
    }

    private function noteDocument(): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [
                [
                    'id' => 'n1',
                    'type' => 'paragraph',
                    'text' => 'Nota con énfasis.',
                    'marks' => [['type' => 'bold', 'start' => 8, 'end' => 16]],
                ],
                ['id' => 'n2', 'type' => 'paragraph', 'text' => 'Segundo párrafo.', 'marks' => []],
            ],
        ];
    }

    public function test_structured_note_content_is_stored_and_plain_text_is_derived(): void
    {
        $user = $this->writer();
        [$novel, $scene] = $this->scene($user);

        $note = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'foot',
                'content' => $this->noteDocument(),
                'scene_id' => $scene->id,
            ])
            ->assertCreated()
            ->json('footnote');

        $this->assertSame("Nota con énfasis.\nSegundo párrafo.", $note['text']);
        $this->assertSame('bold', $note['content']['blocks'][0]['marks'][0]['type']);
        $this->assertSame('n2', $note['content']['blocks'][1]['id']);
    }

    public function test_note_content_can_be_updated_by_the_owner_and_is_private(): void
    {
        $user = $this->writer();
        [$novel] = $this->scene($user);

        $note = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'end',
                'content' => $this->noteDocument(),
            ])
            ->assertCreated()
            ->json('footnote');

        $updated = $this->asWriter($user)
            ->patchJson("/workspace/footnotes/{$note['id']}", [
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [['id' => 'n1', 'type' => 'paragraph', 'text' => 'Nota corregida.', 'marks' => []]],
                ],
            ])
            ->assertOk()
            ->json('footnote');

        $this->assertSame('Nota corregida.', $updated['text']);

        $this->asWriter($this->writer())
            ->patchJson("/workspace/footnotes/{$note['id']}", [
                'content' => $this->noteDocument(),
            ])
            ->assertNotFound();
    }

    public function test_note_content_rejects_structural_blocks_nested_notes_and_empty_bodies(): void
    {
        $user = $this->writer();
        [$novel] = $this->scene($user);

        $cases = [
            ['schema_version' => 1, 'blocks' => [[
                'id' => 't1',
                'type' => 'table',
                'rows' => [['id' => 'r1', 'cells' => [['id' => 'c1', 'text' => 'A']]]],
            ]]],
            ['schema_version' => 1, 'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'text' => 'Nota anidada',
                'marks' => [['type' => 'footnote_reference', 'start' => 0, 'end' => 4, 'attrs' => ['note_id' => 1]]],
            ]]],
            ['schema_version' => 1, 'blocks' => []],
            ['schema_version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => '   ', 'marks' => []]]],
        ];

        foreach ($cases as $index => $content) {
            $this->asWriter($user)
                ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                    'kind' => 'foot',
                    'content' => $content,
                ])
                ->assertStatus(422);
        }
    }

    public function test_legacy_text_only_notes_still_work(): void
    {
        $user = $this->writer();
        [$novel] = $this->scene($user);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'foot',
                'text' => 'Nota sencilla.',
            ])
            ->assertCreated()
            ->assertJsonPath('footnote.text', 'Nota sencilla.');
    }
}
