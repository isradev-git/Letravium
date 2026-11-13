<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardRelationship;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Footnote;
use App\Models\Novel;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\Style;
use App\Models\TextReference;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_owner_creates_universe_novel_and_collection(): void
    {
        $user = $this->writer();

        $universe = $this->asWriter($user)
            ->postJson('/workspace/universes', ['name' => 'Arven'])
            ->assertCreated()
            ->json('universe');

        $novel = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe['id']}/novels", ['title' => 'La carta'])
            ->assertCreated()
            ->json('novel');

        $collection = $this->asWriter($user)
            ->postJson('/workspace/collections', ['name' => 'Saga del faro'])
            ->assertCreated()
            ->json('collection');

        $this->asWriter($user)
            ->postJson("/workspace/collections/{$collection['id']}/novels/{$novel['id']}")
            ->assertOk();

        $this->assertSame($universe['id'], $novel['universe_id']);
        $this->assertSame($user->id, $novel['owner_id']);
    }

    public function test_other_accounts_cannot_archive_or_read_novels(): void
    {
        $owner = $this->writer();
        $universe = Universe::create(['owner_id' => $owner->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $owner->id,
            'title' => 'Privada',
        ]);

        $this->asWriter($this->writer())
            ->patchJson("/workspace/novels/{$novel->id}/archive")
            ->assertNotFound();

        $this->assertNull($novel->fresh()->archived_at);
    }

    public function test_archiving_preserves_references_and_duplicate_novel_copies_manuscript(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->assertCreated()
            ->json('chapter');

        $alternative = $chapter['alternatives'][0];

        $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$alternative['id']}/scenes", [
                'title' => 'Puente',
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'El puente de piedra.']],
                ],
            ])
            ->assertCreated();

        $this->asWriter($user)
            ->patchJson("/workspace/novels/{$novel->id}/archive")
            ->assertOk();

        $this->assertNotNull($novel->fresh()->archived_at);
        $this->assertDatabaseHas('chapters', ['novel_id' => $novel->id]);

        $copy = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/duplicate")
            ->assertCreated()
            ->json('novel');

        $this->assertNotSame($novel->id, $copy['id']);
        $this->assertSame($universe->id, $copy['universe_id']);
        $this->assertSame($user->id, $copy['owner_id']);
        $this->assertDatabaseHas('chapters', ['novel_id' => $copy['id']]);
        $this->assertDatabaseHas('scenes', [
            'chapter_alternative_id' => $this->alternativeOf($copy['id']),
        ]);
    }

    public function test_duplicate_universe_creates_new_card_identities_and_remaps_links(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $elena = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $valdara = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Valdara']);
        CardRelationship::create([
            'universe_id' => $universe->id,
            'source_card_id' => $elena->id,
            'target_card_id' => $valdara->id,
            'label' => 'teme a',
        ]);

        $copy = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/duplicate")
            ->assertCreated()
            ->json('universe');

        $this->assertNotSame($universe->id, $copy['id']);

        $newCards = Card::where('universe_id', $copy['id'])->get();
        $this->assertCount(2, $newCards);
        $this->assertNotContains($elena->id, $newCards->pluck('id')->all());

        $relationship = CardRelationship::where('universe_id', $copy['id'])->firstOrFail();
        $this->assertContains($relationship->source_card_id, $newCards->pluck('id')->all());
        $this->assertContains($relationship->target_card_id, $newCards->pluck('id')->all());
    }

    public function test_duplicate_novel_remaps_styles_notes_plots_and_card_references(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $style = Style::create([
            'novel_id' => $novel->id,
            'name' => 'Énfasis',
            'kind' => 'character',
            'definition' => ['italic' => true],
        ]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'paragraph',
                    'text' => 'El puente.',
                    'marks' => [
                        ['type' => 'character_style', 'start' => 0, 'end' => 9, 'attrs' => ['id' => (string) $style->id]],
                        ['type' => 'footnote_reference', 'start' => 0, 'end' => 9, 'attrs' => ['note_id' => 0]],
                    ],
                ]],
            ],
        ]);
        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota.',
        ]);
        $content = $scene->content;
        $content['blocks'][0]['marks'][1]['attrs']['note_id'] = $note->id;
        $scene->forceFill(['content' => $content])->save();

        $plot = Plot::create(['novel_id' => $novel->id, 'name' => 'Trama principal']);
        DB::table('plot_scene')->insert(['plot_id' => $plot->id, 'scene_id' => $scene->id]);
        TextReference::create(['scene_id' => $scene->id, 'card_id' => $card->id, 'created_by' => $user->id]);

        $copy = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/duplicate")
            ->assertCreated()
            ->json('novel');

        $copyScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn(
                'chapter_id',
                Chapter::where('novel_id', $copy['id'])->pluck('id'),
            )->pluck('id'),
        )->firstOrFail();
        $copyStyle = Style::where('novel_id', $copy['id'])->firstOrFail();
        $copyNote = Footnote::where('novel_id', $copy['id'])->firstOrFail();
        $copyPlot = Plot::where('novel_id', $copy['id'])->firstOrFail();

        $this->assertNotSame($style->id, $copyStyle->id);
        $this->assertNotSame($note->id, $copyNote->id);
        $this->assertNotSame($plot->id, $copyPlot->id);

        $marks = $copyScene->content['blocks'][0]['marks'];
        $this->assertSame((string) $copyStyle->id, $marks[0]['attrs']['id']);
        $this->assertSame($copyNote->id, $marks[1]['attrs']['note_id']);
        $this->assertDatabaseHas('plot_scene', ['plot_id' => $copyPlot->id, 'scene_id' => $copyScene->id]);
        $this->assertDatabaseHas('text_references', ['scene_id' => $copyScene->id, 'card_id' => $card->id]);
    }

    private function alternativeOf(int $novelId): int
    {
        return (int) Novel::findOrFail($novelId)->chapters()
            ->firstOrFail()
            ->alternatives()
            ->firstOrFail()
            ->id;
    }
}
