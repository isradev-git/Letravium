<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\TextReference;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SceneReferencesWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function sceneFor(Novel $novel, string $title = 'El puente'): Scene
    {
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => $title,
            'position' => 0,
        ]);
    }

    public function test_page_lists_context_and_scoped_references(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $scene = $this->sceneFor($novel);
        $elena = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Valdara']);
        $foreign = Card::create(['universe_id' => $this->universeFor($this->writer())->id, 'area' => 'characters', 'name' => 'Ajeno']);
        TextReference::create(['scene_id' => $scene->id, 'card_id' => $elena->id, 'created_by' => $user->id]);

        $this->asWriter($user)
            ->get("/workspace/scenes/{$scene->id}/references")
            ->assertOk()
            ->assertSee('El puente')
            ->assertSee('La carta')
            ->assertSee('Uno')
            ->assertSee('Elena')
            ->assertSee('Valdara')
            ->assertDontSee('Ajeno')
            ->assertSee('action="/workspace/scenes/'.$scene->id.'/references?format=html"', false)
            ->assertSee('name="card_id"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/scenes/{$scene->id}/references")
            ->assertNotFound();
    }

    public function test_html_create_persists_reference_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $scene = $this->sceneFor($novel);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/references?format=html", ['card_id' => $card->id])
            ->assertRedirect("/workspace/scenes/{$scene->id}/references")
            ->assertSessionHas('status');

        $this->assertSame(1, TextReference::where('scene_id', $scene->id)->where('card_id', $card->id)->count());
    }

    public function test_cross_universe_card_is_rejected(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $scene = $this->sceneFor($novel);
        $foreign = Card::create(['universe_id' => $this->universeFor($this->writer())->id, 'area' => 'characters', 'name' => 'Ajeno']);

        $this->asWriter($user)
            ->post("/workspace/scenes/{$scene->id}/references?format=html", ['card_id' => $foreign->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('text_references', 0);
    }

    public function test_json_contracts_remain_json(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $scene = $this->sceneFor($novel);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $this->asWriter($user)
            ->postJson("/workspace/scenes/{$scene->id}/references", ['card_id' => $card->id])
            ->assertCreated()
            ->assertJsonPath('reference.card_id', $card->id);

        $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene->id}/references")
            ->assertOk()
            ->assertJsonCount(1, 'references')
            ->assertJsonPath('references.0.card_id', $card->id);
    }
}
