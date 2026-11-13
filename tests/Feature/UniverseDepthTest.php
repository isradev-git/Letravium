<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\ChapterAlternative;
use App\Models\GlossaryTerm;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Novel;
use App\Models\PlaceContainment;
use App\Models\Scene;
use App\Models\StoryChange;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class UniverseDepthTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user, string $name = 'Arven'): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => $name]);
    }

    private function novelFor($user, Universe $universe, string $title = 'La carta'): Novel
    {
        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function cardFor(Universe $universe, string $area, string $name, array $fields = [], ?string $template = null): Card
    {
        return Card::create([
            'universe_id' => $universe->id,
            'area' => $area,
            'name' => $name,
            'fields' => $fields ?: null,
            'template' => $template,
        ]);
    }

    private function eventFor($user, Universe $universe, string $name, string $type = 'event'): array
    {
        return $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/events", [
                'name' => $name,
                'type' => $type,
            ])->assertCreated()->json('event');
    }

    private function chapterScene($user, Novel $novel, ?string $moment = null): array
    {
        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'narrative_moment' => $moment,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Elena miró el faro.']],
                ],
            ])->json('scene');

        return [$chapter, $scene];
    }

    public function test_card_templates_cover_the_eight_areas_and_accept_provisional_fields(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $templates = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/card-templates")
            ->assertOk()
            ->json('templates');

        foreach (Card::AREAS as $area) {
            $this->assertArrayHasKey($area, $templates);
        }

        $this->assertContains('appearance', $templates['characters']['fields']);
        $this->assertContains('capabilities', $templates['characters']['fields']);
        $this->assertContains('environment', $templates['places']['fields']);
        $this->assertContains('containment', $templates['places']['fields']);
        $this->assertContains('conditions', $templates['rules']['fields']);
        $this->assertContains('exceptions', $templates['rules']['fields']);
        $this->assertContains('family', $templates['groups']['templates']);
        $this->assertContains('government', $templates['groups']['templates']);
        $this->assertContains('event', $templates['history']['templates']);
        $this->assertContains('epoch', $templates['history']['templates']);
        $this->assertContains('unique_object', $templates['objects']['templates']);
        $this->assertContains('resource', $templates['objects']['templates']);
        $this->assertContains('glossary_term', $templates['languages']['templates']);
        $this->assertContains('bibliography', $templates['notes']['templates']);

        foreach (Card::AREAS as $area) {
            $this->asWriter($user)
                ->postJson("/workspace/universes/{$universe->id}/cards", [
                    'area' => $area,
                    'name' => 'Provisional '.$area,
                ])->assertCreated();
        }

        $card = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/cards", [
                'area' => 'characters',
                'name' => 'Elena',
                'template' => 'character',
            ])->assertCreated()->json('card');

        $this->asWriter($user)
            ->patchJson("/workspace/cards/{$card['id']}", [
                'fields' => ['appearance' => 'Un ojo claro', 'capabilities' => 'Cartografía'],
            ])->assertOk()
            ->assertJsonPath('card.fields.appearance', 'Un ojo claro');
    }

    public function test_character_roles_stay_separate_per_novel(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $first = $this->novelFor($user, $universe, 'Primera');
        $second = $this->novelFor($user, $universe, 'Segunda');
        $elena = $this->cardFor($universe, 'characters', 'Elena', ['appearance' => 'Un ojo']);

        $this->asWriter($user)->putJson("/workspace/novels/{$first->id}/cards/{$elena->id}/role", [
            'role' => 'protagonista',
            'goals' => 'encontrar el faro',
            'arc' => 'aprende a confiar',
        ])->assertOk();

        $this->asWriter($user)->putJson("/workspace/novels/{$second->id}/cards/{$elena->id}/role", [
            'role' => 'secundaria',
            'goals' => 'vengar a su hermano',
            'arc' => 'cae',
        ])->assertOk();

        $this->asWriter($user)->getJson("/workspace/novels/{$first->id}/cards/{$elena->id}/context")
            ->assertOk()
            ->assertJsonPath('role.goals', 'encontrar el faro')
            ->assertJsonPath('card.fields.appearance', 'Un ojo');

        $this->asWriter($user)->getJson("/workspace/novels/{$second->id}/cards/{$elena->id}/context")
            ->assertOk()
            ->assertJsonPath('role.goals', 'vengar a su hermano');

        $this->assertSame('Un ojo', $elena->refresh()->fields['appearance']);

        $this->asWriter($this->writer())
            ->getJson("/workspace/novels/{$first->id}/cards/{$elena->id}/context")
            ->assertNotFound();
    }

    public function test_place_containment_accepts_any_depth_and_rejects_cycles(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $city = $this->cardFor($universe, 'places', 'Ciudad');
        $district = $this->cardFor($universe, 'places', 'Barrio');
        $tavern = $this->cardFor($universe, 'places', 'Taberna');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/places/containment", [
            'parent_card_id' => $city->id,
            'child_card_id' => $district->id,
        ])->assertCreated();

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/places/containment", [
            'parent_card_id' => $district->id,
            'child_card_id' => $tavern->id,
        ])->assertCreated();

        $this->assertSame(2, PlaceContainment::count());

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/places/containment", [
            'parent_card_id' => $tavern->id,
            'child_card_id' => $city->id,
        ])->assertStatus(422);

        $this->assertSame(2, PlaceContainment::count());
        $this->assertDatabaseHas('place_containments', ['parent_card_id' => $city->id, 'child_card_id' => $district->id]);
        $this->assertDatabaseHas('place_containments', ['parent_card_id' => $district->id, 'child_card_id' => $tavern->id]);

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/places/{$tavern->id}/hierarchy")
            ->assertOk()
            ->assertJsonCount(3, 'hierarchy')
            ->assertJsonPath('hierarchy.0.name', 'Taberna')
            ->assertJsonPath('hierarchy.2.name', 'Ciudad');
    }

    public function test_events_keep_a_partial_order_and_reject_contradictions_without_blocking_writing(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);

        $a = $this->eventFor($user, $universe, 'Fundación');
        $b = $this->eventFor($user, $universe, 'Batalla');
        $c = $this->eventFor($user, $universe, 'Eclipse');
        $d = $this->eventFor($user, $universe, 'Era antigua', 'epoch');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $a['id'],
            'to_event_id' => $b['id'],
            'type' => 'before',
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/events/{$a['id']}/compare?other_event_id={$b['id']}")
            ->assertOk()->assertJsonPath('relation', 'before');

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/events/{$b['id']}/compare?other_event_id={$a['id']}")
            ->assertOk()->assertJsonPath('relation', 'after');

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/events/{$a['id']}/compare?other_event_id={$c['id']}")
            ->assertOk()->assertJsonPath('relation', 'unknown');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $b['id'],
            'to_event_id' => $a['id'],
            'type' => 'before',
        ])->assertStatus(422)->assertJsonPath('error', 'contradictory_relation');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $a['id'],
            'to_event_id' => $b['id'],
            'type' => 'simultaneous',
        ])->assertStatus(422);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $c['id'],
            'to_event_id' => $b['id'],
            'type' => 'simultaneous',
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/events/{$c['id']}/compare?other_event_id={$a['id']}")
            ->assertOk()->assertJsonPath('relation', 'after');

        $this->asWriter($user)->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Sigue la escritura'])
            ->assertCreated();
    }

    public function test_calendar_conversions_require_an_authored_equivalence(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $first = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/calendars", [
            'name' => 'Era de Arven',
            'config' => ['months' => ['Enero', 'Febrero']],
        ])->assertCreated()->json('calendar');

        $second = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/calendars", [
            'name' => 'Calendario común',
        ])->assertCreated()->json('calendar');

        $calendars = $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/calendars")->json('calendars');
        $this->assertCount(2, $calendars);
        $this->assertFalse((bool) collect($calendars)->firstWhere('id', $first['id'])['is_current']);
        $this->assertNotNull(collect($calendars)->firstWhere('id', $first['id'])['superseded_at']);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/calendar-conversions", [
            'from_calendar_id' => $first['id'],
            'to_calendar_id' => $second['id'],
            'from_label' => '1 de Enero de 1',
            'to_label' => 'Año 1',
        ])->assertCreated();

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/calendar-conversions/resolve", [
            'from_calendar_id' => $first['id'],
            'to_calendar_id' => $second['id'],
            'label' => '1 de Enero de 1',
        ])->assertOk()->assertJsonPath('converted', 'Año 1');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/calendar-conversions/resolve", [
            'from_calendar_id' => $first['id'],
            'to_calendar_id' => $second['id'],
            'label' => '2 de Enero de 1',
        ])->assertStatus(422);
    }

    public function test_story_time_resolves_attributes_and_drafts_keep_their_captured_context(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);

        $early = $this->eventFor($user, $universe, 'Recuerdo');
        $battle = $this->eventFor($user, $universe, 'Batalla');
        $unrelated = $this->eventFor($user, $universe, 'Viaje');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $early['id'],
            'to_event_id' => $battle['id'],
            'type' => 'before',
        ])->assertCreated();

        $elena = $this->cardFor($universe, 'characters', 'Elena', ['eye' => 'intacto']);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-changes", [
            'field' => 'eye',
            'value' => 'perdido',
            'event_id' => $battle['id'],
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-time?event_id={$early['id']}")
            ->assertOk()->assertJsonPath('attributes.eye', 'intacto')
            ->assertJsonPath('uncertain', []);

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-time?event_id={$battle['id']}")
            ->assertOk()->assertJsonPath('attributes.eye', 'perdido');

        $uncertain = $this->cardFor($universe, 'characters', 'Valdara', ['eye' => 'intacto']);
        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/cards/{$uncertain->id}/story-changes", [
            'field' => 'eye',
            'value' => 'herido',
            'event_id' => $unrelated['id'],
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/cards/{$uncertain->id}/story-time?event_id={$battle['id']}")
            ->assertOk()->assertJsonPath('fields.eye.uncertain', true)
            ->assertJsonPath('uncertain.0', 'eye');

        [, $scene] = $this->chapterScene($user, $novel, (string) $early['id']);
        $changesBefore = StoryChange::count();

        $this->asWriter($user)->postJson("/workspace/scenes/{$scene['id']}/story-context", [
            'card_id' => $elena->id,
            'event_id' => $early['id'],
        ])->assertCreated()->assertJsonPath('context.context.attributes.eye', 'intacto');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-changes", [
            'field' => 'eye',
            'value' => 'curado',
            'event_id' => $battle['id'],
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/scenes/{$scene['id']}/story-context")
            ->assertOk()->assertJsonPath('context.context.attributes.eye', 'intacto');

        $this->assertSame($changesBefore + 1, StoryChange::count());
        $this->assertSame('intacto', $elena->refresh()->fields['eye']);
    }

    public function test_continuity_warnings_show_origin_data_and_can_be_dismissed(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);

        $early = $this->eventFor($user, $universe, 'Antes del nacimiento');
        $birth = $this->eventFor($user, $universe, 'Nacimiento');
        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $early['id'],
            'to_event_id' => $birth['id'],
            'type' => 'before',
        ])->assertCreated();

        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/cards/{$elena->id}/story-changes", [
            'field' => 'birth_event',
            'value' => $birth['id'],
            'event_id' => $birth['id'],
        ])->assertCreated();

        [, $scene] = $this->chapterScene($user, $novel, (string) $early['id']);
        $this->asWriter($user)->postJson("/workspace/scenes/{$scene['id']}/references", [
            'card_id' => $elena->id,
        ])->assertCreated();

        $warnings = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/continuity-warnings")
            ->assertOk()
            ->json('warnings');

        $birthWarning = collect($warnings)->firstWhere('code', 'scene_before_birth');
        $this->assertNotNull($birthWarning);
        $this->assertSame($scene['id'], $birthWarning['facts']['scene_id']);
        $this->assertSame($elena->id, $birthWarning['facts']['card_id']);
        $this->assertSame($birth['id'], $birthWarning['facts']['birth_event_id']);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/continuity-warnings/dismiss", [
            'fingerprint' => $birthWarning['fingerprint'],
        ])->assertOk();

        $afterDismiss = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/continuity-warnings")
            ->json('warnings');
        $this->assertNull(collect($afterDismiss)->firstWhere('code', 'scene_before_birth'));

        $temporary = $this->cardFor($universe, 'characters', 'Temporal');
        $note = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/notes", [
            'body' => 'Nota vinculada',
            'links' => [['type' => 'card', 'id' => $temporary->id]],
        ])->assertCreated()->json('note');

        $temporary->delete();

        $removed = collect($this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/continuity-warnings")
            ->json('warnings'))->firstWhere('code', 'removed_link');

        $this->assertNotNull($removed);
        $this->assertSame($note['id'], $removed['facts']['note_id']);
        $this->assertSame($temporary->id, $removed['facts']['linkable_id']);
    }

    public function test_unique_objects_have_custody_by_moment_and_resources_have_many_origins(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $early = $this->eventFor($user, $universe, 'Entrega');
        $later = $this->eventFor($user, $universe, 'Robo');
        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/event-relations", [
            'from_event_id' => $early['id'],
            'to_event_id' => $later['id'],
            'type' => 'before',
        ])->assertCreated();

        $letter = $this->cardFor($universe, 'objects', 'Carta del faro', ['object_kind' => 'unique']);
        $elena = $this->cardFor($universe, 'characters', 'Elena');
        $valdara = $this->cardFor($universe, 'characters', 'Valdara');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody", [
            'holder_card_id' => $elena->id,
            'event_id' => $early['id'],
        ])->assertCreated();

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody", [
            'holder_card_id' => $valdara->id,
            'event_id' => $later['id'],
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody?event_id={$early['id']}")
            ->assertOk()->assertJsonPath('holder_card_id', $elena->id);

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/objects/{$letter->id}/custody?event_id={$later['id']}")
            ->assertOk()->assertJsonPath('holder_card_id', $valdara->id);

        $material = $this->cardFor($universe, 'objects', 'Acero de Valdara', ['object_kind' => 'resource']);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/objects/{$material->id}/custody", [
            'holder_card_id' => $elena->id,
        ])->assertStatus(422);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/resources/{$material->id}/origins", [
            'place_card_id' => $letter->id,
            'origin' => 'Minas del norte',
        ])->assertCreated();

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/resources/{$material->id}/origins", [
            'origin' => 'Chatarra del puerto',
        ])->assertCreated();

        $this->asWriter($user)->getJson("/workspace/universes/{$universe->id}/resources/{$material->id}/origins")
            ->assertOk()->assertJsonCount(2, 'origins');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/resources/{$letter->id}/origins", [
            'origin' => 'No aplica',
        ])->assertStatus(422);
    }

    public function test_glossary_terms_do_not_become_spelling_or_card_data(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $language = $this->cardFor($universe, 'languages', 'Sural');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/glossary-terms", [
            'term' => 'sural',
            'meaning' => 'Viento del sur',
        ])->assertCreated()->assertJsonPath('term.language_card_id', null);

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/glossary-terms", [
            'language_card_id' => $language->id,
            'term' => 'valdarés',
            'meaning' => 'Lengua de Valdara',
            'preferred_form' => 'valdarés',
            'variants' => ['valdares'],
            'examples' => ['Sural valdarés.'],
        ])->assertCreated()->assertJsonPath('term.language_card_id', $language->id);

        $this->assertSame(2, GlossaryTerm::where('universe_id', $universe->id)->count());
        $this->assertSame(1, Card::where('universe_id', $universe->id)->count());
        $this->assertDatabaseMissing('cards', ['name' => 'valdarés']);

        $terms = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/glossary-terms")
            ->assertOk()->json('terms');
        $this->assertCount(2, $terms);
    }

    public function test_private_notes_survive_container_deletion_without_leaking(): void
    {
        $owner = $this->writer();
        $collaborator = $this->writer();
        $universe = $this->universeFor($owner);
        $first = $this->cardFor($universe, 'places', 'Valdara');
        $second = $this->cardFor($universe, 'characters', 'Elena');

        $note = Note::create([
            'universe_id' => $universe->id,
            'author_id' => $collaborator->id,
            'title' => 'Investigación',
            'body' => 'Contenido privado',
            'private' => true,
        ]);

        NoteLink::create(['note_id' => $note->id, 'linkable_type' => 'card', 'linkable_id' => $first->id]);
        NoteLink::create(['note_id' => $note->id, 'linkable_type' => 'card', 'linkable_id' => $second->id]);

        $this->assertSame(1, Note::count());
        $this->assertSame(2, NoteLink::where('note_id', $note->id)->count());

        $ownerNotes = $this->asWriter($owner)
            ->getJson("/workspace/universes/{$universe->id}/notes")
            ->assertOk()->json('notes');
        $this->assertSame([], $ownerNotes);

        $universe->delete();

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'body' => 'Contenido privado']);

        $personal = $this->asWriter($collaborator)
            ->getJson('/workspace/notes')
            ->assertOk()->json('notes');

        $this->assertCount(1, $personal);
        $this->assertSame('Contenido privado', $personal[0]['body']);
        $this->assertSame(['detached', 'detached'], array_column($personal[0]['links'], 'status'));

        $this->asWriter($owner)
            ->getJson("/workspace/universes/{$universe->id}/notes")
            ->assertNotFound();
    }

    public function test_bibliography_exports_only_selected_entries_not_private_notes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);

        $note = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/notes", [
            'title' => 'Fuente privada',
            'html' => '<p>secreto de investigación</p>',
        ])->assertCreated()->json('note');

        $entry = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/bibliography", [
            'novel_id' => $novel->id,
            'author' => 'Autora',
            'title' => 'Historia de Arven',
            'date' => '2020',
            'publisher' => 'Editorial',
        ])->assertCreated()->json('entry');

        $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/bibliography", [
            'novel_id' => $novel->id,
            'title' => 'Entrada no elegida',
        ])->assertCreated();

        $this->asWriter($user)->postJson("/workspace/bibliography/{$entry['id']}/notes/{$note['id']}")
            ->assertOk();

        $this->assertSame([], $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/bibliography")
            ->assertOk()->json('entries'));

        $this->asWriter($user)->patchJson("/workspace/bibliography/{$entry['id']}/selected", [
            'selected' => true,
        ])->assertOk();

        $response = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/bibliography")
            ->assertOk();

        $entries = $response->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame('Historia de Arven', $entries[0]['title']);
        $this->assertStringNotContainsString('secreto de investigación', $response->getContent());
    }

    public function test_pending_notes_can_be_marked_and_resolved_without_touching_text_or_state(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        [$chapter, $scene] = $this->chapterScene($user, $novel);

        $state = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/work-states", ['name' => 'Por revisar'])
            ->json('work_state');

        $this->asWriter($user)->putJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/work-state", [
            'work_state_id' => $state['id'],
        ])->assertOk();

        $note = $this->asWriter($user)->postJson("/workspace/universes/{$universe->id}/notes", [
            'title' => 'Pendiente',
            'body' => 'Revisar el faro',
            'pending' => true,
            'links' => [['type' => 'scene', 'id' => $scene['id']]],
        ])->assertCreated()->json('note');

        $pendings = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/pendings")
            ->assertOk()->json('pendings');
        $this->assertCount(1, $pendings);
        $this->assertSame('Revisar el faro', $pendings[0]['body']);

        $this->asWriter($user)->patchJson("/workspace/notes/{$note['id']}/pending", [
            'pending' => false,
        ])->assertOk()->assertJsonPath('note.pending', false);

        $this->assertNotNull(Note::find($note['id'])->resolved_at);

        $this->assertSame([], $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/pendings")
            ->assertOk()->json('pendings'));

        $freshScene = Scene::find($scene['id']);
        $this->assertSame('Elena miró el faro.', $freshScene->content['blocks'][0]['text']);
        $this->assertSame($state['id'], ChapterAlternative::find($chapter['alternatives'][0]['id'])->work_state_id);

        $this->asWriter($this->writer())
            ->getJson("/workspace/novels/{$novel->id}/pendings")
            ->assertNotFound();
    }
}
