<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class PlanningPageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function chapterFor(Novel $novel, array $plan = []): Chapter
    {
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => 'Uno',
            'position' => 0,
            'plan' => $plan,
        ]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
            'plan' => ['summary' => 'Alternativa inicial'],
        ]);
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'summary' => 'Elena llega al faro.',
            'narrative_moment' => 'Apertura',
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Uno dos tres']],
            ],
        ]);

        return $chapter;
    }

    public function test_planning_page_offers_chapter_order_controls_and_html_reorder_redirects(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $one = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $two = Chapter::create(['novel_id' => $novel->id, 'title' => 'Dos', 'position' => 1]);
        $three = Chapter::create(['novel_id' => $novel->id, 'title' => 'Tres', 'position' => 2]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertOk()
            ->assertSee('Subir')
            ->assertSee('Bajar')
            ->assertSee('name="order[]"', false);

        $this->asWriter($user)
            ->patch("/workspace/novels/{$novel->id}/chapters/order?format=html", [
                'order' => [$three->id, $one->id, $two->id],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertSame(0, (int) $three->fresh()->position);
        $this->assertSame(1, (int) $one->fresh()->position);
        $this->assertSame(2, (int) $two->fresh()->position);

        $this->asWriter($user)
            ->patch("/workspace/novels/{$novel->id}/chapters/order?format=html", [
                'order' => [$one->id, $one->id, $two->id],
            ])
            ->assertStatus(422);
    }

    public function test_planning_page_lists_plots_and_linked_scenes(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $scene = $chapter->alternatives()->first()->scenes()->first();
        $plot = Plot::create(['novel_id' => $novel->id, 'name' => 'La carta', 'description' => 'El faro guarda una promesa.']);
        DB::table('plot_scene')->insert(['plot_id' => $plot->id, 'scene_id' => $scene->id]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertOk()
            ->assertSee('Tramas')
            ->assertSee('La carta')
            ->assertSee('El faro guarda una promesa.')
            ->assertSee('El faro')
            ->assertSee('name="description"', false)
            ->assertSee("/workspace/scenes/{$scene->id}/plots", false);
    }

    public function test_html_plot_creation_and_scene_linking_persist_and_redirect(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $scene = $chapter->alternatives()->first()->scenes()->first();

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/plots?format=html", [
                'name' => 'La promesa',
                'description' => 'Una deuda pendiente.',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $plot = Plot::where('novel_id', $novel->id)->where('name', 'La promesa')->firstOrFail();
        $this->assertSame('Una deuda pendiente.', $plot->description);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/plots?format=html", ['plot_id' => $plot->id])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertDatabaseHas('plot_scene', ['plot_id' => $plot->id, 'scene_id' => $scene->id]);
    }

    public function test_plot_linking_rejects_a_scene_from_another_novel(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $plot = Plot::create(['novel_id' => $novel->id, 'name' => 'La carta']);
        $otherNovel = $this->novelFor($user);
        $otherChapter = $this->chapterFor($otherNovel);
        $otherScene = $otherChapter->alternatives()->first()->scenes()->first();

        $this->asWriter($user)
            ->post("/workspace/scenes/{$otherScene->id}/plots?format=html", ['plot_id' => $plot->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('plot_scene', ['plot_id' => $plot->id, 'scene_id' => $otherScene->id]);
    }

    public function test_planning_page_renders_scene_plan_forms_and_card_options(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel, ['summary' => 'Plan inicial']);
        $scene = $chapter->alternatives()->first()->scenes()->first();
        $scene->forceFill(['plan' => ['intent' => 'Presentar a Elena', 'characters' => 'Elena, el farero']])->save();
        $universe = Universe::findOrFail($novel->universe_id);
        Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'El faro']);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertOk()
            ->assertSee('Guardar plan de la escena')
            ->assertSee('El faro')
            ->assertSee('Elena llega al faro.')
            ->assertSee('Apertura')
            ->assertSee('Presentar a Elena')
            ->assertSee('Elena, el farero')
            ->assertSee('name="pov_card_id"', false)
            ->assertSee('name="place_card_id"', false)
            ->assertSee('Elena')
            ->assertSee('El faro');
    }

    public function test_html_scene_plan_form_persists_and_redirects(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $scene = $chapter->alternatives()->first()->scenes()->first();
        $scene->forceFill(['plan' => ['notes' => 'Nota de escena']])->save();
        $universe = Universe::findOrFail($novel->universe_id);
        $character = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $place = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'El faro']);

        $this->asWriter($user)
            ->put("/workspace/scenes/{$scene->id}?format=html", [
                'title' => 'La llegada',
                'summary' => 'Elena sube al faro.',
                'narrative_moment' => 'Incidente incitador',
                'pov_card_id' => $character->id,
                'place_card_id' => $place->id,
                'plan' => ['intent' => 'Mostrar su soledad', 'characters' => 'Elena'],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $scene->refresh();
        $this->assertSame('La llegada', $scene->title);
        $this->assertSame('Elena sube al faro.', $scene->summary);
        $this->assertSame('Incidente incitador', $scene->narrative_moment);
        $this->assertSame($character->id, (int) $scene->pov_card_id);
        $this->assertSame($place->id, (int) $scene->place_card_id);
        $this->assertSame('Mostrar su soledad', $scene->plan['intent']);
        $this->assertSame('Elena', $scene->plan['characters']);
        $this->assertSame('Nota de escena', $scene->plan['notes']);
    }

    public function test_scene_plan_rejects_a_card_from_another_universe_or_area(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $scene = $chapter->alternatives()->first()->scenes()->first();
        $foreignUniverse = Universe::create(['owner_id' => $this->writer()->id, 'name' => 'Ajena']);
        $foreignCharacter = Card::create(['universe_id' => $foreignUniverse->id, 'area' => 'characters', 'name' => 'Intrusa']);
        $wrongArea = Card::create(['universe_id' => $novel->universe_id, 'area' => 'places', 'name' => 'Un lugar']);

        $this->asWriter($user)
            ->put("/workspace/scenes/{$scene->id}?format=html", [
                'summary' => 'No debe guardarse',
                'pov_card_id' => $foreignCharacter->id,
            ])
            ->assertStatus(422);

        $this->asWriter($user)
            ->put("/workspace/scenes/{$scene->id}?format=html", [
                'summary' => 'No debe guardarse',
                'pov_card_id' => $wrongArea->id,
            ])
            ->assertStatus(422);

        $this->assertSame('Elena llega al faro.', $scene->fresh()->summary);
    }

    public function test_planning_page_shows_counts_goal_states_and_plans_to_the_owner(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel, ['summary' => 'Plan inicial', 'notes' => 'Nota privada']);
        $state = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/work-states", ['name' => 'Escribiendo'])
            ->json('work_state');

        $this->asWriter($user)
            ->putJson("/workspace/alternatives/{$chapter->alternatives()->first()->id}/work-state", [
                'work_state_id' => $state['id'],
            ])->assertOk();
        $this->asWriter($user)
            ->putJson("/workspace/novels/{$novel->id}/goal", ['target_words' => 500])
            ->assertOk();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertOk()
            ->assertSee('Planificación')
            ->assertSee('La carta')
            ->assertSee('3 palabras')
            ->assertSee('Plan inicial')
            ->assertSee('Nota privada')
            ->assertSee('Escribiendo')
            ->assertSee('500')
            ->assertSee('Alternativa inicial')
            ->assertSee('El faro')
            ->assertSee('Elena llega al faro.')
            ->assertSee('name="plan[summary]"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertNotFound();
    }

    public function test_html_chapter_and_alternative_plan_forms_merge_and_redirect(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel, ['summary' => 'Plan inicial', 'notes' => 'Nota privada']);
        $alternative = $chapter->alternatives()->first();

        $this->asWriter($user)
            ->put("/workspace/chapters/{$chapter->id}/plan?format=html", [
                'plan' => ['summary' => 'Resumen revisado', 'intent' => 'Mostrar la llegada'],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $chapter->refresh();
        $this->assertSame('Resumen revisado', $chapter->plan['summary']);
        $this->assertSame('Mostrar la llegada', $chapter->plan['intent']);
        $this->assertSame('Nota privada', $chapter->plan['notes']);

        $this->asWriter($user)
            ->put("/workspace/alternatives/{$alternative->id}/plan?format=html", [
                'plan' => ['summary' => 'Alternativa revisada'],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertSame('Alternativa revisada', $alternative->fresh()->plan['summary']);
    }

    public function test_html_work_state_create_assign_and_rename_forms_persist(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $alternative = $chapter->alternatives()->first();

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/work-states?format=html", ['name' => 'Escribiendo'])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $state = DB::table('work_states')->where('novel_id', $novel->id)->where('name', 'Escribiendo')->firstOrFail();

        $this->asWriter($user)
            ->put("/workspace/alternatives/{$alternative->id}/work-state?format=html", [
                'work_state_id' => $state->id,
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertSame((int) $state->id, (int) $alternative->fresh()->work_state_id);

        $this->asWriter($user)
            ->patch("/workspace/work-states/{$state->id}?format=html", ['name' => 'Revisando'])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertSame('Revisando', DB::table('work_states')->where('id', $state->id)->value('name'));
        $this->assertSame((int) $state->id, (int) $alternative->fresh()->work_state_id);
    }

    public function test_html_goal_form_updates_the_target_and_redirects(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->put("/workspace/novels/{$novel->id}/goal?format=html", ['target_words' => 1200])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertSame(1200, (int) DB::table('novel_goals')->where('novel_id', $novel->id)->value('target_words'));
    }

    public function test_planning_page_shows_part_alternative_forms_and_character_roles_link(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $active = $chapter->alternatives()->first();
        $inactive = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Final alternativo',
            'is_active' => false,
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/planning")
            ->assertOk()
            ->assertSee('Roles de personajes')
            ->assertSee("/workspace/novels/{$novel->id}/character-roles", false)
            ->assertSee('Crear parte')
            ->assertSee("/workspace/novels/{$novel->id}/parts?format=html", false)
            ->assertSee('Crear alternativa')
            ->assertSee("/workspace/chapters/{$chapter->id}/alternatives?format=html", false)
            ->assertSee($active->alias)
            ->assertSee($inactive->alias)
            ->assertSee("/workspace/alternatives/{$inactive->id}/activate?format=html", false);
    }

    public function test_html_part_creation_persists_and_redirects_while_json_is_unchanged(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/parts", ['title' => 'Primera parte'])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertDatabaseHas('parts', ['novel_id' => $novel->id, 'title' => 'Primera parte']);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/parts", ['title' => 'Segunda parte'])
            ->assertCreated()
            ->assertJsonPath('part.title', 'Segunda parte');
    }

    public function test_html_alternative_creation_and_activation_keep_one_active_alternative(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $chapter = $this->chapterFor($novel);
        $first = $chapter->alternatives()->first();

        $this->asWriter($user)
            ->post("/workspace/chapters/{$chapter->id}/alternatives?format=html", ['alias' => 'Final alternativo'])
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $second = ChapterAlternative::where('chapter_id', $chapter->id)
            ->where('alias', 'Final alternativo')
            ->firstOrFail();
        $this->assertFalse((bool) $second->is_active);
        $this->assertTrue((bool) $first->fresh()->is_active);
        $this->assertSame(1, ChapterAlternative::where('chapter_id', $chapter->id)->where('is_active', true)->count());

        $this->asWriter($user)
            ->patch("/workspace/alternatives/{$second->id}/activate?format=html")
            ->assertRedirect("/workspace/novels/{$novel->id}/planning");

        $this->assertTrue((bool) $second->fresh()->is_active);
        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertSame(1, ChapterAlternative::where('chapter_id', $chapter->id)->where('is_active', true)->count());

        $this->asWriter($user)
            ->postJson("/workspace/chapters/{$chapter->id}/alternatives", ['alias' => 'Otra', 'active' => true])
            ->assertCreated()
            ->assertJsonPath('alternative.alias', 'Otra');
        $this->assertSame(1, ChapterAlternative::where('chapter_id', $chapter->id)->where('is_active', true)->count());

        $this->asWriter($user)
            ->patchJson("/workspace/alternatives/{$first->id}/activate")
            ->assertOk()
            ->assertJsonPath('alternative.id', $first->id);
        $this->assertSame(1, ChapterAlternative::where('chapter_id', $chapter->id)->where('is_active', true)->count());
    }
}
