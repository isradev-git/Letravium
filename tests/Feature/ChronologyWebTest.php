<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\EventRelation;
use App\Models\StoryEvent;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ChronologyWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function cardFor(Universe $universe, string $area, string $name): Card
    {
        return Card::create(['universe_id' => $universe->id, 'area' => $area, 'name' => $name]);
    }

    private function eventFor(Universe $universe, string $name, string $type = 'event'): StoryEvent
    {
        return StoryEvent::create(['universe_id' => $universe->id, 'name' => $name, 'type' => $type]);
    }

    public function test_page_lists_events_and_relations_and_hides_foreign_universes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $plaza = $this->cardFor($universe, 'places', 'Plaza');

        $foundation = $this->eventFor($universe, 'Fundación');
        $foundation->update([
            'description' => 'Primer asentamiento',
            'moment' => 'Año 1',
            'participants' => [$elena->id],
            'places' => [$plaza->id],
        ]);
        $battle = $this->eventFor($universe, 'Batalla');

        EventRelation::create([
            'universe_id' => $universe->id,
            'from_event_id' => $foundation->id,
            'to_event_id' => $battle->id,
            'relation' => 'before',
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/events")
            ->assertOk()
            ->assertSee('Cronología')
            ->assertSee('Fundación')
            ->assertSee('Batalla')
            ->assertSee('antes que')
            ->assertSee('Elena')
            ->assertSee('Plaza')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/events?format=html"', false)
            ->assertSee('action="/workspace/universes/'.$universe->id.'/event-relations?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/events")
            ->assertNotFound();
    }

    public function test_html_create_event_and_relation_redirect(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $plaza = $this->cardFor($universe, 'places', 'Plaza');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/events?format=html", [
                'name' => 'Fundación',
                'type' => 'epoch',
                'description' => 'Primer asentamiento',
                'moment' => 'Año 1',
                'participants' => [$elena->id],
                'places' => [$plaza->id],
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/events")
            ->assertSessionHas('status');

        $foundation = StoryEvent::where('universe_id', $universe->id)->where('name', 'Fundación')->firstOrFail();
        $this->assertSame('epoch', $foundation->type);
        $this->assertEquals([$elena->id], array_map('intval', $foundation->participants));
        $this->assertEquals([$plaza->id], array_map('intval', $foundation->places));

        $battle = $this->eventFor($universe, 'Batalla');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/event-relations?format=html", [
                'from_event_id' => $foundation->id,
                'to_event_id' => $battle->id,
                'type' => 'before',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/events")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('event_relations', [
            'universe_id' => $universe->id,
            'from_event_id' => $foundation->id,
            'to_event_id' => $battle->id,
            'relation' => 'before',
        ]);
    }

    public function test_contradictory_relation_stays_422(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $foundation = $this->eventFor($universe, 'Fundación');
        $battle = $this->eventFor($universe, 'Batalla');

        EventRelation::create([
            'universe_id' => $universe->id,
            'from_event_id' => $foundation->id,
            'to_event_id' => $battle->id,
            'relation' => 'before',
        ]);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/event-relations?format=html", [
                'from_event_id' => $battle->id,
                'to_event_id' => $foundation->id,
                'type' => 'before',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'contradictory_relation');

        $this->assertSame(1, EventRelation::where('universe_id', $universe->id)->count());
    }

    public function test_compare_page_renders_and_json_contract_is_unchanged(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $foundation = $this->eventFor($universe, 'Fundación');
        $battle = $this->eventFor($universe, 'Batalla');

        EventRelation::create([
            'universe_id' => $universe->id,
            'from_event_id' => $foundation->id,
            'to_event_id' => $battle->id,
            'relation' => 'before',
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/events/{$foundation->id}/compare?other_event_id={$battle->id}")
            ->assertOk()
            ->assertSee('Comparación')
            ->assertSee('Fundación')
            ->assertSee('Batalla')
            ->assertSee('antes que');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/events/{$foundation->id}/compare?other_event_id={$battle->id}")
            ->assertOk()
            ->assertJsonPath('relation', 'before');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/events")
            ->assertOk()
            ->assertJsonCount(2, 'events')
            ->assertJsonCount(1, 'relations');

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/events", ['name' => 'Eclipse', 'type' => 'event'])
            ->assertCreated()
            ->assertJsonPath('event.name', 'Eclipse');
    }
}
