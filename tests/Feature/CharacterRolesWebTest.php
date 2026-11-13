<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CharacterRole;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CharacterRolesWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function novelFor(User $user, Universe $universe, string $title = 'La carta'): Novel
    {
        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function cardFor(Universe $universe, string $name = 'Elena'): Card
    {
        return Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => $name]);
    }

    public function test_owner_page_lists_roles_and_update_form_and_hides_foreign_novels(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        $elena = $this->cardFor($universe, 'Elena');

        CharacterRole::create([
            'novel_id' => $novel->id,
            'card_id' => $elena->id,
            'role' => 'protagonista',
            'goals' => 'encontrar el faro',
            'arc' => 'aprende a confiar',
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/character-roles")
            ->assertOk()
            ->assertSee('Roles de personajes')
            ->assertSee('Elena')
            ->assertSee('protagonista')
            ->assertSee('encontrar el faro')
            ->assertSee('aprende a confiar')
            ->assertSee('value="protagonista"', false)
            ->assertSee('action="/workspace/novels/'.$novel->id.'/cards/'.$elena->id.'/role?format=html"', false)
            ->assertSee('name="_method" value="PUT"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/character-roles")
            ->assertNotFound();
    }

    public function test_html_update_persists_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        $elena = $this->cardFor($universe, 'Elena');

        $this->asWriter($user)
            ->put("/workspace/novels/{$novel->id}/cards/{$elena->id}/role?format=html", [
                'role' => 'secundaria',
                'goals' => 'vengar a su hermano',
                'arc' => 'cae',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/character-roles")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('character_roles', [
            'novel_id' => $novel->id,
            'card_id' => $elena->id,
            'role' => 'secundaria',
            'goals' => 'vengar a su hermano',
            'arc' => 'cae',
        ]);
    }

    public function test_a_character_without_a_role_can_be_given_one_from_the_page(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        $elena = $this->cardFor($universe, 'Elena');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/character-roles")
            ->assertOk()
            ->assertSee('Sin rol asignado')
            ->assertSee('action="/workspace/novels/'.$novel->id.'/cards/'.$elena->id.'/role?format=html"', false);

        $this->asWriter($user)
            ->put("/workspace/novels/{$novel->id}/cards/{$elena->id}/role?format=html", [
                'role' => 'protagonista',
                'goals' => 'encontrar el faro',
                'arc' => 'aprende a confiar',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/character-roles")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('character_roles', [
            'novel_id' => $novel->id,
            'card_id' => $elena->id,
            'role' => 'protagonista',
            'goals' => 'encontrar el faro',
        ]);
    }

    public function test_a_card_from_another_universe_is_rejected(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        $foreign = $this->cardFor($this->universeFor($this->writer()), 'Ajeno');

        $this->asWriter($user)
            ->put("/workspace/novels/{$novel->id}/cards/{$foreign->id}/role?format=html", [
                'role' => 'intrusa',
            ])
            ->assertNotFound();

        $this->assertSame(0, CharacterRole::where('novel_id', $novel->id)->count());
    }

    public function test_json_contracts_remain_json(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($user, $universe);
        $elena = $this->cardFor($universe, 'Elena');

        $this->asWriter($user)
            ->putJson("/workspace/novels/{$novel->id}/cards/{$elena->id}/role", [
                'role' => 'protagonista',
                'goals' => 'encontrar el faro',
                'arc' => 'aprende a confiar',
            ])
            ->assertOk()
            ->assertJsonPath('role.role', 'protagonista')
            ->assertJsonPath('role.goals', 'encontrar el faro');

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/character-roles")
            ->assertOk()
            ->assertJsonCount(1, 'roles')
            ->assertJsonPath('roles.0.card.name', 'Elena')
            ->assertJsonPath('roles.0.role', 'protagonista');

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/cards/{$elena->id}/context")
            ->assertOk()
            ->assertJsonPath('card.name', 'Elena')
            ->assertJsonPath('role.role', 'protagonista');
    }
}
