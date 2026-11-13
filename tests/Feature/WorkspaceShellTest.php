<?php

namespace Tests\Feature;

use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceShellTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/workspace')->assertRedirect('/login');
    }

    public function test_authorized_writer_sees_own_library_and_no_other_account_titles(): void
    {
        $owner = $this->writer();
        $universe = Universe::create(['owner_id' => $owner->id, 'name' => 'Arven']);
        Novel::create(['universe_id' => $universe->id, 'owner_id' => $owner->id, 'title' => 'La carta']);

        $stranger = $this->writer();
        $foreign = Universe::create(['owner_id' => $stranger->id, 'name' => 'Reino ajeno']);
        Novel::create(['universe_id' => $foreign->id, 'owner_id' => $stranger->id, 'title' => 'Secreto']);

        $this->asWriter($owner)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSee('workspace-page', false)
            ->assertSee('Biblioteca')
            ->assertSee('Arven')
            ->assertSee('La carta')
            ->assertSee('/workspace/novels/'.Novel::where('title', 'La carta')->first()->id, false)
            ->assertDontSee('Reino ajeno')
            ->assertDontSee('Secreto');
    }

    public function test_empty_library_explains_what_to_create(): void
    {
        $this->asWriter($this->writer())
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Todavía no hay universos')
            ->assertSee('action="/workspace/universes"', false);
    }

    public function test_api_requests_keep_the_existing_json_contract(): void
    {
        $this->asWriter($this->writer())
            ->getJson('/workspace')
            ->assertOk()
            ->assertExactJson(['area' => 'creative']);
    }

    public function test_creating_a_universe_from_the_shell_returns_to_the_shell(): void
    {
        $this->asWriter($this->writer())
            ->post('/workspace/universes', ['name' => 'Nuevo mundo'])
            ->assertRedirect('/workspace');

        $this->assertDatabaseHas('universes', ['name' => 'Nuevo mundo']);
    }

    public function test_creating_a_novel_from_the_shell_returns_to_the_shell(): void
    {
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/novels", ['title' => 'Otra novela'])
            ->assertRedirect('/workspace');

        $this->assertDatabaseHas('novels', ['title' => 'Otra novela', 'universe_id' => $universe->id]);
    }
}
