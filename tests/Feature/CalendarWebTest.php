<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\CalendarConversion;
use App\Models\Card;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\StoryEvent;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CalendarWebTest extends TestCase
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

    public function test_page_lists_calendars_and_conversions_and_hides_foreign_universes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $first = Calendar::create(['universe_id' => $universe->id, 'name' => 'Era de Arven', 'is_current' => false]);
        $second = Calendar::create(['universe_id' => $universe->id, 'name' => 'Calendario común', 'is_current' => true]);
        CalendarConversion::create([
            'universe_id' => $universe->id,
            'from_calendar_id' => $first->id,
            'to_calendar_id' => $second->id,
            'from_label' => '1 de Enero de 1',
            'to_label' => 'Año 1',
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/calendars")
            ->assertOk()
            ->assertSee('Calendarios')
            ->assertSee('Era de Arven')
            ->assertSee('Calendario común')
            ->assertSee('Conversiones')
            ->assertSee('1 de Enero de 1')
            ->assertSee('Año 1')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/calendars?format=html"', false)
            ->assertSee('action="/workspace/universes/'.$universe->id.'/calendar-conversions?format=html"', false)
            ->assertSee('action="/workspace/universes/'.$universe->id.'/calendar-conversions/resolve?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/calendars")
            ->assertNotFound();
    }

    public function test_html_calendar_conversion_and_resolve_redirect(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/calendars?format=html", ['name' => 'Era de Arven'])
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars")
            ->assertSessionHas('status');

        $first = Calendar::where('universe_id', $universe->id)->where('name', 'Era de Arven')->firstOrFail();

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/calendars?format=html", ['name' => 'Calendario común'])
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars");

        $second = Calendar::where('universe_id', $universe->id)->where('name', 'Calendario común')->firstOrFail();
        $this->assertTrue($second->is_current);
        $this->assertFalse($first->refresh()->is_current);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/calendar-conversions?format=html", [
                'from_calendar_id' => $first->id,
                'to_calendar_id' => $second->id,
                'from_label' => '1 de Enero de 1',
                'to_label' => 'Año 1',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('calendar_conversions', [
            'universe_id' => $universe->id,
            'from_calendar_id' => $first->id,
            'to_calendar_id' => $second->id,
            'from_label' => '1 de Enero de 1',
            'to_label' => 'Año 1',
        ]);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/calendar-conversions/resolve?format=html", [
                'from_calendar_id' => $first->id,
                'to_calendar_id' => $second->id,
                'label' => '1 de Enero de 1',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars")
            ->assertSessionHas('conversion_result', 'Año 1');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/calendar-conversions/resolve?format=html", [
                'from_calendar_id' => $first->id,
                'to_calendar_id' => $second->id,
                'label' => '2 de Enero de 1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_authored_equivalence');
    }

    public function test_story_change_story_time_and_warning_dismiss_redirect(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $battle = StoryEvent::create(['universe_id' => $universe->id, 'name' => 'Batalla', 'type' => 'event']);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-changes?format=html", [
                'field' => 'eye',
                'value' => 'perdido',
                'event_id' => $battle->id,
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('story_changes', [
            'universe_id' => $universe->id,
            'card_id' => $elena->id,
            'field' => 'eye',
            'effective_event_id' => $battle->id,
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-time?event_id={$battle->id}&format=html")
            ->assertRedirect("/workspace/universes/{$universe->id}/calendars")
            ->assertSessionHas('story_time');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/continuity-warnings/dismiss?format=html", [
                'fingerprint' => 'abc123',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/continuity-warnings")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('continuity_dismissals', [
            'universe_id' => $universe->id,
            'user_id' => $user->id,
            'fingerprint' => 'abc123',
        ]);
    }

    public function test_warnings_page_renders_and_json_contracts_are_unchanged(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        Calendar::create(['universe_id' => $universe->id, 'name' => 'Era de Arven', 'is_current' => true]);

        $note = Note::create([
            'universe_id' => $universe->id,
            'author_id' => $user->id,
            'body' => 'Nota vinculada',
            'private' => true,
        ]);
        NoteLink::create([
            'note_id' => $note->id,
            'linkable_type' => 'card',
            'linkable_id' => 9999,
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/continuity-warnings")
            ->assertOk()
            ->assertSee('Avisos de continuidad')
            ->assertSee('Una nota apunta a un elemento retirado');

        $calendars = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/calendars")
            ->assertOk()
            ->assertJsonCount(1, 'calendars');
        $this->assertArrayNotHasKey('conversions', $calendars->json());

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/continuity-warnings")
            ->assertOk()
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0.code', 'removed_link');

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/calendars", ['name' => 'Nueva era'])
            ->assertCreated()
            ->assertJsonPath('calendar.name', 'Nueva era');

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/continuity-warnings/dismiss", ['fingerprint' => 'xyz'])
            ->assertOk()
            ->assertJsonPath('status', 'dismissed');
    }
}
