<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\ObjectCustody;
use App\Models\PlaceContainment;
use App\Models\ResourceOrigin;
use App\Models\StoryEvent;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CardLinksWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function cardFor(Universe $universe, string $area, string $name, array $fields = []): Card
    {
        return Card::create([
            'universe_id' => $universe->id,
            'area' => $area,
            'name' => $name,
            'fields' => $fields ?: null,
        ]);
    }

    private function contain(Universe $universe, Card $parent, Card $child): void
    {
        PlaceContainment::create([
            'universe_id' => $universe->id,
            'parent_card_id' => $parent->id,
            'child_card_id' => $child->id,
        ]);
    }

    private function selectedValues(string $html, string $selectId): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $values = [];
        foreach ((new \DOMXPath($dom))->query('//select[@id="'.$selectId.'"]//option') as $option) {
            $values[] = $option->getAttribute('value');
        }

        return $values;
    }

    public function test_place_card_shows_containment_chain_and_parent_options_skip_self_and_descendants(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $city = $this->cardFor($universe, 'places', 'Ciudad');
        $district = $this->cardFor($universe, 'places', 'Barrio');
        $tavern = $this->cardFor($universe, 'places', 'Taberna');
        $other = $this->cardFor($universe, 'places', 'Bosque');
        $this->contain($universe, $city, $district);
        $this->contain($universe, $district, $tavern);

        $this->asWriter($user)
            ->get("/workspace/cards/{$tavern->id}")
            ->assertOk()
            ->assertSee('Contención')
            ->assertSee('Ciudad › Barrio › Taberna')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/places/containment?format=html"', false)
            ->assertSee('name="child_card_id" value="'.$tavern->id.'"', false);

        $cityResponse = $this->asWriter($user)->get("/workspace/cards/{$city->id}")->assertOk();
        $parentValues = $this->selectedValues($cityResponse->getContent(), 'containment-parent');

        $this->assertContains((string) $other->id, $parentValues);
        $this->assertNotContains((string) $city->id, $parentValues);
        $this->assertNotContains((string) $district->id, $parentValues);
        $this->assertNotContains((string) $tavern->id, $parentValues);
    }

    public function test_html_containment_redirects_and_self_or_cycle_stays_422(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $city = $this->cardFor($universe, 'places', 'Ciudad');
        $district = $this->cardFor($universe, 'places', 'Barrio');
        $tavern = $this->cardFor($universe, 'places', 'Taberna');
        $this->contain($universe, $city, $district);
        $this->contain($universe, $district, $tavern);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/places/containment?format=html", [
                'parent_card_id' => $city->id,
                'child_card_id' => $tavern->id,
            ])
            ->assertRedirect("/workspace/cards/{$tavern->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('place_containments', [
            'parent_card_id' => $city->id,
            'child_card_id' => $tavern->id,
        ]);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/places/containment?format=html", [
                'parent_card_id' => $city->id,
                'child_card_id' => $city->id,
            ])
            ->assertStatus(422);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/places/containment?format=html", [
                'parent_card_id' => $tavern->id,
                'child_card_id' => $city->id,
            ])
            ->assertStatus(422);

        $this->assertSame(2, PlaceContainment::count());
    }

    public function test_unique_object_page_shows_custody_history_and_html_add_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $letter = $this->cardFor($universe, 'objects', 'Carta del faro', ['object_kind' => 'unique']);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $event = StoryEvent::create(['universe_id' => $universe->id, 'name' => 'Entrega', 'type' => 'event']);

        ObjectCustody::create([
            'universe_id' => $universe->id,
            'card_id' => $letter->id,
            'holder_card_id' => $elena->id,
            'event_id' => $event->id,
            'moment' => 'invierno',
        ]);

        $this->asWriter($user)
            ->get("/workspace/cards/{$letter->id}")
            ->assertOk()
            ->assertSee('Custodia')
            ->assertSee('Elena')
            ->assertSee('Entrega')
            ->assertSee('invierno')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/objects/'.$letter->id.'/custody?format=html"', false)
            ->assertSee('id="custody-holder"', false);

        $valdara = $this->cardFor($universe, 'characters', 'Valdara');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody?format=html", [
                'holder_card_id' => $valdara->id,
                'event_id' => $event->id,
                'moment' => 'verano',
            ])
            ->assertRedirect("/workspace/cards/{$letter->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('object_custodies', [
            'card_id' => $letter->id,
            'holder_card_id' => $valdara->id,
            'moment' => 'verano',
        ]);
    }

    public function test_resource_page_shows_origins_and_html_add_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $material = $this->cardFor($universe, 'objects', 'Acero de Valdara', ['object_kind' => 'resource']);
        $mines = $this->cardFor($universe, 'places', 'Minas del norte');

        ResourceOrigin::create([
            'universe_id' => $universe->id,
            'card_id' => $material->id,
            'origin' => 'Veta profunda',
            'place_card_id' => $mines->id,
        ]);

        $this->asWriter($user)
            ->get("/workspace/cards/{$material->id}")
            ->assertOk()
            ->assertSee('Procedencias')
            ->assertSee('Veta profunda')
            ->assertSee('Minas del norte')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/resources/'.$material->id.'/origins?format=html"', false)
            ->assertSee('id="origin-place"', false);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/resources/{$material->id}/origins?format=html", [
                'origin' => 'Chatarra del puerto',
            ])
            ->assertRedirect("/workspace/cards/{$material->id}")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('resource_origins', [
            'card_id' => $material->id,
            'origin' => 'Chatarra del puerto',
        ]);
    }

    public function test_link_forms_only_appear_for_the_matching_area(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $character = $this->cardFor($universe, 'characters', 'Elena');
        $place = $this->cardFor($universe, 'places', 'Ciudad');
        $unique = $this->cardFor($universe, 'objects', 'Carta', ['object_kind' => 'unique']);
        $resource = $this->cardFor($universe, 'objects', 'Acero', ['object_kind' => 'resource']);

        $this->asWriter($user)->get("/workspace/cards/{$character->id}")
            ->assertOk()
            ->assertDontSee('/places/containment?format=html', false)
            ->assertDontSee('/objects/'.$character->id.'/custody?format=html', false)
            ->assertDontSee('/resources/'.$character->id.'/origins?format=html', false);

        $this->asWriter($user)->get("/workspace/cards/{$place->id}")
            ->assertOk()
            ->assertDontSee('/objects/'.$place->id.'/custody?format=html', false)
            ->assertDontSee('/resources/'.$place->id.'/origins?format=html', false);

        $this->asWriter($user)->get("/workspace/cards/{$unique->id}")
            ->assertOk()
            ->assertSee('/objects/'.$unique->id.'/custody?format=html', false)
            ->assertDontSee('/resources/'.$unique->id.'/origins?format=html', false);

        $this->asWriter($user)->get("/workspace/cards/{$resource->id}")
            ->assertOk()
            ->assertSee('/resources/'.$resource->id.'/origins?format=html', false)
            ->assertDontSee('/objects/'.$resource->id.'/custody?format=html', false);
    }

    public function test_json_links_contracts_remain_unchanged(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $city = $this->cardFor($universe, 'places', 'Ciudad');
        $district = $this->cardFor($universe, 'places', 'Barrio');
        $letter = $this->cardFor($universe, 'objects', 'Carta', ['object_kind' => 'unique']);
        $material = $this->cardFor($universe, 'objects', 'Acero', ['object_kind' => 'resource']);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $event = StoryEvent::create(['universe_id' => $universe->id, 'name' => 'Entrega', 'type' => 'event']);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/places/containment", [
                'parent_card_id' => $city->id,
                'child_card_id' => $district->id,
            ])
            ->assertCreated()
            ->assertJsonPath('containment.parent_card_id', $city->id)
            ->assertJsonPath('containment.child_card_id', $district->id);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody", [
                'holder_card_id' => $elena->id,
                'event_id' => $event->id,
            ])
            ->assertCreated()
            ->assertJsonPath('custody.holder_card_id', $elena->id);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/resources/{$material->id}/origins", [
                'origin' => 'Minas del norte',
            ])
            ->assertCreated()
            ->assertJsonPath('origin.origin', 'Minas del norte');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/places/{$district->id}/hierarchy")
            ->assertOk()
            ->assertJsonCount(2, 'hierarchy')
            ->assertJsonPath('hierarchy.1.name', 'Ciudad');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody?event_id={$event->id}")
            ->assertOk()
            ->assertJsonPath('holder_card_id', $elena->id);

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/resources/{$material->id}/origins")
            ->assertOk()
            ->assertJsonCount(1, 'origins');
    }
}
