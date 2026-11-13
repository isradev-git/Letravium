<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class WorkspaceLibraryManageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user, string $title = 'La carta'): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);

        return [$universe, $novel];
    }

    public function test_library_shows_lifecycle_and_collection_controls(): void
    {
        $user = $this->writer();
        [$universe, $novel] = $this->novelFor($user);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Colecciones')
            ->assertSee('action="/workspace/collections?format=html"', false)
            ->assertSee('action="/workspace/universes/'.$universe->id.'/duplicate?format=html"', false)
            ->assertSee('action="/workspace/novels/'.$novel->id.'/duplicate?format=html"', false)
            ->assertSee('action="/workspace/novels/'.$novel->id.'/archive?format=html"', false);
    }

    public function test_html_duplicate_actions_redirect_and_create_owner_copies(): void
    {
        $user = $this->writer();
        [$universe, $novel] = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/duplicate?format=html")
            ->assertRedirect('/workspace');

        $this->assertDatabaseHas('novels', [
            'title' => 'La carta (copia)',
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
        ]);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/duplicate?format=html")
            ->assertRedirect('/workspace');

        $this->assertDatabaseHas('universes', [
            'name' => 'Arven (copia)',
            'owner_id' => $user->id,
        ]);
    }

    public function test_archive_lists_the_novel_and_unarchive_returns_it(): void
    {
        $user = $this->writer();
        [, $novel] = $this->novelFor($user);

        $this->asWriter($user)
            ->patch("/workspace/novels/{$novel->id}/archive?format=html")
            ->assertRedirect('/workspace');

        $this->assertNotNull($novel->fresh()->archived_at);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Archivadas')
            ->assertSee('action="/workspace/novels/'.$novel->id.'/unarchive?format=html"', false);

        $this->asWriter($user)
            ->patch("/workspace/novels/{$novel->id}/unarchive?format=html")
            ->assertRedirect('/workspace');

        $this->assertNull($novel->fresh()->archived_at);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('action="/workspace/novels/'.$novel->id.'/duplicate?format=html"', false)
            ->assertDontSee('action="/workspace/novels/'.$novel->id.'/unarchive?format=html"', false);
    }

    public function test_html_collection_actions_redirect_and_show_the_novel(): void
    {
        $user = $this->writer();
        [, $novel] = $this->novelFor($user);

        $this->asWriter($user)
            ->post('/workspace/collections?format=html', ['name' => 'Saga del faro'])
            ->assertRedirect('/workspace');

        $collection = Collection::where('owner_id', $user->id)->firstOrFail();

        $this->asWriter($user)
            ->post("/workspace/collections/{$collection->id}/novels/{$novel->id}?format=html")
            ->assertRedirect('/workspace');

        $this->assertSame([$novel->id], $collection->novels()->pluck('novels.id')->all());

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Saga del faro')
            ->assertSee('La carta');
    }

    public function test_repeated_collection_adds_keep_an_already_ordered_novel_in_place(): void
    {
        $user = $this->writer();
        [, $first] = $this->novelFor($user, 'Uno');
        [, $second] = $this->novelFor($user, 'Dos');
        $collection = Collection::create(['owner_id' => $user->id, 'name' => 'Saga']);

        foreach ([$first, $second] as $novel) {
            $this->asWriter($user)
                ->postJson("/workspace/collections/{$collection->id}/novels/{$novel->id}")
                ->assertOk();
        }

        $this->asWriter($user)
            ->patchJson("/workspace/collections/{$collection->id}/novels/{$second->id}/move", ['direction' => 'up'])
            ->assertOk();

        $order = fn () => $collection->novels()->pluck('novels.id')->all();
        $this->assertSame([$second->id, $first->id], $order());

        $this->asWriter($user)
            ->postJson("/workspace/collections/{$collection->id}/novels/{$second->id}")
            ->assertOk();
        $this->assertSame([$second->id, $first->id], $order());

        $this->asWriter($user)
            ->post("/workspace/collections/{$collection->id}/novels/{$second->id}?format=html")
            ->assertRedirect('/workspace');
        $this->assertSame([$second->id, $first->id], $order());
    }

    public function test_foreign_accounts_cannot_manage_the_library_or_collections(): void
    {
        $owner = $this->writer();
        [$universe, $novel] = $this->novelFor($owner);
        $collection = Collection::create(['owner_id' => $owner->id, 'name' => 'Saga']);
        $stranger = $this->writer();

        foreach ([
            ['post', "/workspace/novels/{$novel->id}/duplicate"],
            ['patch', "/workspace/novels/{$novel->id}/archive"],
            ['patch', "/workspace/novels/{$novel->id}/unarchive"],
            ['post', "/workspace/universes/{$universe->id}/duplicate"],
            ['post', "/workspace/collections/{$collection->id}/novels/{$novel->id}"],
        ] as [$method, $uri]) {
            $this->asWriter($stranger)->{$method}($uri)->assertNotFound();
        }

        $this->assertNull($novel->fresh()->archived_at);
        $this->assertSame(1, Novel::where('owner_id', $owner->id)->count());
        $this->assertSame(1, Universe::where('owner_id', $owner->id)->count());
        $this->assertCount(0, $collection->novels()->get());
    }

    public function test_json_contracts_are_preserved(): void
    {
        $user = $this->writer();
        [, $novel] = $this->novelFor($user);

        $copy = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/duplicate")
            ->assertCreated()
            ->json('novel');
        $this->assertSame($user->id, $copy['owner_id']);

        $this->asWriter($user)
            ->patchJson("/workspace/novels/{$novel->id}/archive")
            ->assertOk()
            ->assertJsonPath('novel.id', $novel->id);

        $this->asWriter($user)
            ->patchJson("/workspace/novels/{$novel->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('novel.id', $novel->id);

        $collection = $this->asWriter($user)
            ->postJson('/workspace/collections', ['name' => 'Saga'])
            ->assertCreated()
            ->json('collection');

        $this->asWriter($user)
            ->postJson("/workspace/collections/{$collection['id']}/novels/{$novel->id}")
            ->assertOk();
    }
}
