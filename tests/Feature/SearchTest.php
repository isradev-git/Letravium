<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor(User $user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function scene(Novel $novel, string $title, string $text, bool $active = true): Scene
    {
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => $title,
            'position' => (int) $novel->chapters()->max('position') + 1,
        ]);

        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => $active ? 'Principal' : 'Descartada',
            'is_active' => $active,
        ]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
            ],
        ]);
    }

    private function search(User $user, Novel $novel, array $params): array
    {
        return $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/search?".http_build_query($params))
            ->assertOk()
            ->json();
    }

    public function test_search_has_a_browser_surface_with_a_form_and_linked_results(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $scene = $this->scene($novel, 'Uno', 'El faro brilla.');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/search")
            ->assertOk()
            ->assertSee('Buscar en La carta')
            ->assertSee('name="q"', false)
            ->assertSee('Todavía no hay búsqueda');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/search?q=faro")
            ->assertOk()
            ->assertSee('El faro brilla.')
            ->assertSee("href=\"/workspace/novels/{$novel->id}#scene-{$scene->id}\"", false);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/search?q=faro")
            ->assertNotFound();
    }

    public function test_search_distinguishes_ene_and_supports_accent_and_case_modes(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->scene($novel, 'Uno', 'La caña del río.');
        $this->scene($novel, 'Dos', 'Una cana larga.');
        $this->scene($novel, 'Tres', 'Una caná distinta.');

        $plain = $this->search($user, $novel, ['q' => 'cana']);
        $this->assertSame(['Una cana larga.', 'Una caná distinta.'], array_column($plain['results'], 'excerpt'));

        $ene = $this->search($user, $novel, ['q' => 'caña']);
        $this->assertSame(['La caña del río.'], array_column($ene['results'], 'excerpt'));

        $exact = $this->search($user, $novel, ['q' => 'caná', 'mode' => 'exact']);
        $this->assertSame(['Una caná distinta.'], array_column($exact['results'], 'excerpt'));

        $case = $this->search($user, $novel, ['q' => 'CANA', 'case' => 'sensitive']);
        $this->assertSame([], $case['results']);
    }

    public function test_search_filters_by_resource_type_card_area_and_tags(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->scene($novel, 'Uno', 'El puerto del norte.');
        Card::create([
            'universe_id' => $novel->universe_id,
            'area' => 'places',
            'name' => 'Puerto del norte',
            'fields' => ['tags' => ['norte', 'mar']],
        ]);
        Card::create([
            'universe_id' => $novel->universe_id,
            'area' => 'characters',
            'name' => 'Norte',
            'fields' => ['tags' => ['norte']],
        ]);

        $scenesOnly = $this->search($user, $novel, ['q' => 'norte', 'type' => 'scene']);
        $this->assertCount(1, $scenesOnly['results']);
        $this->assertSame('scene', $scenesOnly['results'][0]['type']);

        $cards = $this->search($user, $novel, ['q' => 'norte', 'type' => 'card', 'tags' => ['mar']]);
        $this->assertCount(1, $cards['results']);
        $this->assertSame('Puerto del norte', $cards['results'][0]['title']);
        $this->assertSame('places', $cards['results'][0]['area']);

        $places = $this->search($user, $novel, ['q' => 'norte', 'type' => 'card', 'area' => 'characters']);
        $this->assertCount(1, $places['results']);
        $this->assertSame('Norte', $places['results'][0]['title']);
    }

    public function test_search_defaults_to_active_alternatives_and_is_private_to_the_owner(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->scene($novel, 'Vigente', 'Contenido vigente.');
        $this->scene($novel, 'Descartada', 'Contenido oculto.', false);

        $this->assertSame([], $this->search($user, $novel, ['q' => 'oculto'])['results']);
        $this->assertSame(['Contenido vigente.'], array_column(
            $this->search($user, $novel, ['q' => 'vigente', 'type' => 'scene'])['results'],
            'excerpt',
        ));

        $other = $this->writer();
        $this->asWriter($other)
            ->getJson("/workspace/novels/{$novel->id}/search?q=vigente")
            ->assertNotFound();
    }

    public function test_chapter_and_universe_scopes_are_explicit_and_authorized(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $firstNovel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $secondNovel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'El mapa',
        ]);

        $this->scene($firstNovel, 'Uno', 'El faro ilumina la costa.');
        $this->scene($secondNovel, 'Dos', 'El dragón duerme en la cueva.');
        $this->scene($secondNovel, 'Tres', 'Otro faro lejano.');

        $firstChapter = Chapter::where('novel_id', $firstNovel->id)->orderBy('position')->firstOrFail();
        $secondChapter = Chapter::where('novel_id', $secondNovel->id)->orderBy('position')->firstOrFail();

        Card::create([
            'universe_id' => $universe->id,
            'area' => 'places',
            'name' => 'Faro del norte',
            'fields' => ['tags' => ['mar']],
        ]);

        $chapterScoped = $this->asWriter($user)
            ->getJson("/workspace/chapters/{$firstChapter->id}/search?q=faro")
            ->assertOk()
            ->json();

        $this->assertCount(1, $chapterScoped['results']);
        $this->assertSame($firstNovel->id, $chapterScoped['results'][0]['novel_id']);

        $chapterLeak = $this->asWriter($user)
            ->getJson("/workspace/chapters/{$firstChapter->id}/search?q=drag%C3%B3n")
            ->assertOk()
            ->json();
        $this->assertSame([], $chapterLeak['results']);
        $this->assertStringNotContainsString('Dos', json_encode($chapterLeak, JSON_UNESCAPED_UNICODE));

        $universeScoped = $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/search?q=faro")
            ->assertOk()
            ->json();

        $this->assertCount(3, $universeScoped['results']);
        $this->assertContains($secondNovel->id, array_column($universeScoped['results'], 'novel_id'));
        $this->assertContains('card', array_column($universeScoped['results'], 'type'));

        $foreignUniverse = Universe::create(['owner_id' => $this->writer()->id, 'name' => 'Ajena']);

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$foreignUniverse->id}/search?q=faro")
            ->assertNotFound();

        $this->asWriter($this->writer())
            ->getJson("/workspace/chapters/{$secondChapter->id}/search?q=faro")
            ->assertNotFound();
    }
}
