<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\TextReference;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CardTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    public function test_all_eight_areas_accept_a_provisional_card_with_name_only(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        foreach (Card::AREAS as $area) {
            $this->asWriter($user)
                ->postJson("/workspace/universes/{$universe->id}/cards", [
                    'area' => $area,
                    'name' => 'Provisional '.$area,
                ])->assertCreated();
        }

        $this->assertSame(8, Card::where('universe_id', $universe->id)->count());

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/cards", [
                'area' => 'spaceships',
                'name' => 'No válida',
            ])->assertStatus(422);
    }

    public function test_cards_have_a_browser_surface_for_area_navigation_creation_and_editing(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $elena = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Valdara']);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/cards?area=characters")
            ->assertOk()
            ->assertSee('Fichas del universo')
            ->assertSee('Personajes')
            ->assertSee('Elena')
            ->assertSee('Valdara')
            ->assertSee("/workspace/cards/{$elena->id}", false);

        $this->asWriter($user)
            ->get("/workspace/cards/{$elena->id}")
            ->assertOk()
            ->assertSee('Elena')
            ->assertSee('Identidad')
            ->assertSee('name="fields[identity]"', false);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/cards", [
                'area' => 'characters',
                'name' => 'Nuevo personaje',
            ])
            ->assertRedirect();

        $created = Card::where('universe_id', $universe->id)->where('name', 'Nuevo personaje')->firstOrFail();

        $this->asWriter($user)
            ->patch("/workspace/cards/{$created->id}", [
                'name' => 'Personaje revisado',
                'fields' => ['identity' => 'Guardiana del faro'],
            ])
            ->assertRedirect("/workspace/cards/{$created->id}");

        $created->refresh();
        $this->assertSame('Personaje revisado', $created->name);
        $this->assertSame('Guardiana del faro', $created->fields['identity']);

        $valdara = Card::where('universe_id', $universe->id)->where('name', 'Valdara')->firstOrFail();

        $this->asWriter($user)
            ->get("/workspace/cards/{$elena->id}")
            ->assertOk()
            ->assertSee('Valdara')
            ->assertSee('name="target_card_id"', false);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/relationships", [
                'source_card_id' => $elena->id,
                'target_card_id' => $valdara->id,
                'label' => 'viaja a',
            ])
            ->assertRedirect("/workspace/cards/{$elena->id}");

        $this->assertDatabaseHas('card_relationships', [
            'universe_id' => $universe->id,
            'source_card_id' => $elena->id,
            'target_card_id' => $valdara->id,
            'label' => 'viaja a',
        ]);

        $this->asWriter($this->writer())
            ->get("/workspace/cards/{$elena->id}")
            ->assertNotFound();
    }

    public function test_card_detail_lists_scene_references_and_can_move_the_card_to_trash(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El puente',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Elena cruza.']]],
        ]);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        TextReference::create(['scene_id' => $scene->id, 'card_id' => $card->id, 'created_by' => $user->id]);

        $this->asWriter($user)
            ->get("/workspace/cards/{$card->id}")
            ->assertOk()
            ->assertSee('Escenas que la referencian')
            ->assertSee('El puente')
            ->assertSee("/workspace/novels/{$novel->id}#scene-{$scene->id}", false)
            ->assertSee("/workspace/card/{$card->id}/trash", false);

        $this->asWriter($user)
            ->post("/workspace/card/{$card->id}/trash")
            ->assertRedirect('/workspace/trash');

        $this->assertNull(Card::find($card->id));
    }

    public function test_relationship_is_directional_and_survives_rename(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $elena = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $valdara = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Valdara']);

        $relationship = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/relationships", [
                'source_card_id' => $elena->id,
                'target_card_id' => $valdara->id,
                'label' => 'teme a',
            ])->assertCreated()->json('relationship');

        $all = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/relationships")
            ->assertOk()
            ->json('relationships');

        $this->assertCount(1, $all);
        $this->assertSame($elena->id, $all[0]['source_card_id']);
        $this->assertSame($valdara->id, $all[0]['target_card_id']);

        $this->asWriter($user)
            ->patchJson("/workspace/cards/{$elena->id}", ['name' => 'Elena de Valdara'])
            ->assertOk();

        $this->assertSame(
            $relationship['source_card_id'],
            (int) $this->asWriter($user)
                ->getJson("/workspace/universes/{$universe->id}/relationships")
                ->json('relationships.0.source_card_id'),
        );
    }

    public function test_references_are_explicit_stable_and_scoped_to_the_universe(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Elena miró el faro.']],
                ],
            ])->json('scene');

        $this->assertDatabaseCount('text_references', 0);

        $reference = $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene['id']}/references", ['card_id' => $card->id])
            ->assertCreated()
            ->json('reference');

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene['id']}/references", ['card_id' => $card->id])
            ->assertCreated();

        $this->assertSame(1, TextReference::where('scene_id', $scene['id'])->count());
        $this->assertSame($card->id, $reference['card_id']);

        $this->asWriter($this->writer())
            ->postJson("/workspace/scenes/{$scene['id']}/references", ['card_id' => $card->id])
            ->assertNotFound();
    }
}
