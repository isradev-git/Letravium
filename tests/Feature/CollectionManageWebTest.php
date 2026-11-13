<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CollectionManageWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function library($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $first = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'Uno']);
        $second = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'Dos']);
        $collection = Collection::create(['owner_id' => $user->id, 'name' => 'Saga']);
        $collection->novels()->attach($first->id, ['position' => 0]);
        $collection->novels()->attach($second->id, ['position' => 1]);

        return [$collection, $first, $second];
    }

    public function test_library_shows_collection_edit_controls(): void
    {
        $user = $this->writer();
        [$collection, $first] = $this->library($user);

        $this->asWriter($user)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('action="/workspace/collections/'.$collection->id.'?format=html"', false)
            ->assertSee('action="/workspace/collections/'.$collection->id.'/novels/'.$first->id.'/move?format=html"', false)
            ->assertSee('action="/workspace/collections/'.$collection->id.'/novels/'.$first->id.'?format=html"', false);
    }

    public function test_collection_can_be_renamed_removed_and_deleted(): void
    {
        $user = $this->writer();
        [$collection, $first, $second] = $this->library($user);

        $this->asWriter($user)
            ->patch("/workspace/collections/{$collection->id}?format=html", ['name' => 'Saga revisada'])
            ->assertRedirect('/workspace');
        $this->assertSame('Saga revisada', $collection->fresh()->name);

        $this->asWriter($user)
            ->delete("/workspace/collections/{$collection->id}/novels/{$first->id}?format=html")
            ->assertRedirect('/workspace');
        $this->assertSame([$second->id], $collection->novels()->pluck('novels.id')->all());
        $this->assertDatabaseHas('novels', ['id' => $first->id]);

        $this->asWriter($user)
            ->delete("/workspace/collections/{$collection->id}?format=html")
            ->assertRedirect('/workspace');
        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseHas('novels', ['id' => $second->id]);
    }

    public function test_collection_order_can_be_changed(): void
    {
        $user = $this->writer();
        [$collection, $first, $second] = $this->library($user);

        $this->asWriter($user)
            ->patch("/workspace/collections/{$collection->id}/novels/{$second->id}/move?format=html", ['direction' => 'up'])
            ->assertRedirect('/workspace');

        $this->assertSame([$second->id, $first->id], $collection->novels()->pluck('novels.id')->all());

        $this->asWriter($user)
            ->patchJson("/workspace/collections/{$collection->id}/novels/{$first->id}/move", ['direction' => 'up'])
            ->assertOk()
            ->assertJsonPath('order.0', $first->id);
    }

    public function test_edges_and_foreign_accounts_are_rejected(): void
    {
        $owner = $this->writer();
        [$collection, $first] = $this->library($owner);
        $stranger = $this->writer();

        $this->asWriter($owner)
            ->patchJson("/workspace/collections/{$collection->id}/novels/{$first->id}/move", ['direction' => 'up'])
            ->assertStatus(422);

        $this->asWriter($stranger)
            ->patch("/workspace/collections/{$collection->id}", ['name' => 'Robada'])
            ->assertNotFound();
        $this->asWriter($stranger)
            ->delete("/workspace/collections/{$collection->id}?format=html")
            ->assertNotFound();
        $this->asWriter($stranger)
            ->delete("/workspace/collections/{$collection->id}/novels/{$first->id}?format=html")
            ->assertNotFound();
        $this->asWriter($stranger)
            ->patch("/workspace/collections/{$collection->id}/novels/{$first->id}/move?format=html", ['direction' => 'down'])
            ->assertNotFound();

        $this->assertSame('Saga', $collection->fresh()->name);
        $this->assertCount(2, $collection->novels()->get());
    }

    public function test_json_contracts_for_new_collection_actions(): void
    {
        $user = $this->writer();
        [$collection, $first] = $this->library($user);

        $this->asWriter($user)
            ->patchJson("/workspace/collections/{$collection->id}", ['name' => 'Otra saga'])
            ->assertOk()
            ->assertJsonPath('collection.name', 'Otra saga');

        $this->asWriter($user)
            ->deleteJson("/workspace/collections/{$collection->id}/novels/{$first->id}")
            ->assertOk()
            ->assertJsonPath('collection.novels', fn ($novels) => count($novels) === 1);

        $this->asWriter($user)
            ->deleteJson("/workspace/collections/{$collection->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
    }
}
