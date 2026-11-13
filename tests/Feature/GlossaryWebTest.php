<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\GlossaryTerm;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class GlossaryWebTest extends TestCase
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

    public function test_page_lists_owner_terms_with_language_and_hides_foreign_universes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $language = $this->cardFor($universe, 'languages', 'Sural');

        GlossaryTerm::create([
            'universe_id' => $universe->id,
            'language_card_id' => $language->id,
            'term' => 'valdarés',
            'meaning' => 'Lengua de Valdara',
            'preferred_form' => 'valdarés',
            'variants' => ['valdares'],
            'examples' => ['Sural valdarés.'],
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/glossary-terms")
            ->assertOk()
            ->assertSee('Glosario')
            ->assertSee('valdarés')
            ->assertSee('Sural')
            ->assertSee('valdares')
            ->assertSee('Sural valdarés.')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/glossary-terms?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/glossary-terms")
            ->assertNotFound();
    }

    public function test_html_create_persists_text_lists_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $language = $this->cardFor($universe, 'languages', 'Sural');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/glossary-terms?format=html", [
                'term' => 'valdarés',
                'meaning' => 'Lengua de Valdara',
                'preferred_form' => 'valdarés',
                'language_card_id' => $language->id,
                'variants_text' => "valdares, valdarés antiguo\nvald",
                'examples_text' => "Sural valdarés.\nBruma valdara.",
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/glossary-terms")
            ->assertSessionHas('status');

        $term = GlossaryTerm::where('term', 'valdarés')->firstOrFail();
        $this->assertSame($language->id, $term->language_card_id);
        $this->assertSame(['valdares', 'valdarés antiguo', 'vald'], $term->variants);
        $this->assertSame(['Sural valdarés.', 'Bruma valdara.'], $term->examples);
    }

    public function test_foreign_language_card_is_rejected(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $foreign = $this->cardFor($this->universeFor($this->writer()), 'languages', 'Ajeno');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/glossary-terms", [
                'language_card_id' => $foreign->id,
                'term' => 'valdarés',
            ])
            ->assertStatus(422);

        $this->assertSame(0, GlossaryTerm::where('universe_id', $universe->id)->count());
    }

    public function test_json_contracts_remain_json(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/glossary-terms", [
                'term' => 'sural',
                'meaning' => 'Viento del sur',
                'variants' => ['surales'],
                'examples' => ['Sural fuerte.'],
            ])
            ->assertCreated()
            ->assertJsonPath('term.term', 'sural')
            ->assertJsonPath('term.variants', ['surales']);

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/glossary-terms")
            ->assertOk()
            ->assertJsonCount(1, 'terms')
            ->assertJsonPath('terms.0.term', 'sural');
    }
}
