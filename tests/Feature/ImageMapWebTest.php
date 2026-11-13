<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\ImageMap;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ImageMapWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function novelFor(Universe $universe, User $user): Novel
    {
        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function imageFor(Universe $universe, User $user, string $name = 'mapa.png'): MediaAsset
    {
        return MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'kind' => 'image',
            'original_name' => $name,
            'mime' => 'image/png',
            'bytes' => 10,
            'path' => 'media/'.$user->id.'/'.$name,
        ]);
    }

    private function placeFor(Universe $universe, string $name): Card
    {
        return Card::create([
            'universe_id' => $universe->id,
            'area' => 'places',
            'name' => $name,
        ]);
    }

    public function test_universe_page_lists_maps_with_preview_and_hides_foreign_universes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $asset = $this->imageFor($universe, $user);
        $place = $this->placeFor($universe, 'Puerto');

        ImageMap::create([
            'universe_id' => $universe->id,
            'image_asset_id' => $asset->id,
            'markers' => [[
                'place_card_id' => $place->id,
                'x' => 0.25,
                'y' => 0.5,
                'label' => 'Puerto',
                'note' => 'Muelle viejo.',
            ]],
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/image-maps")
            ->assertOk()
            ->assertSee('Mapas')
            ->assertSee('mapa.png')
            ->assertSee('/workspace/assets/'.$asset->id.'/view', false)
            ->assertSee('Puerto')
            ->assertSee('Muelle viejo.')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/image-maps?format=html"', false)
            ->assertSee('action="/workspace/image-maps/', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/image-maps")
            ->assertNotFound();
    }

    public function test_novel_page_lists_maps_and_hides_foreign_novels(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($universe, $user);
        $asset = $this->imageFor($universe, $user);

        ImageMap::create([
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'image_asset_id' => $asset->id,
            'markers' => [],
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/image-maps")
            ->assertOk()
            ->assertSee('La carta')
            ->assertSee('mapa.png')
            ->assertSee('/workspace/assets/'.$asset->id.'/view', false)
            ->assertSee('action="/workspace/novels/'.$novel->id.'/image-maps?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/image-maps")
            ->assertNotFound();
    }

    public function test_html_create_persists_coordinates_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $asset = $this->imageFor($universe, $user);
        $place = $this->placeFor($universe, 'Puerto');

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/image-maps?format=html", [
                'image_asset_id' => $asset->id,
                'place_card_id' => $place->id,
                'x' => '0.25',
                'y' => '0.75',
                'label' => 'Puerto',
                'note' => 'Muelle viejo.',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/image-maps")
            ->assertSessionHas('status');

        $map = ImageMap::firstOrFail();
        $this->assertSame($asset->id, $map->image_asset_id);
        $this->assertCount(1, $map->markers);
        $this->assertSame($place->id, (int) $map->markers[0]['place_card_id']);
        $this->assertEqualsWithDelta(0.25, (float) $map->markers[0]['x'], 0.0001);
        $this->assertEqualsWithDelta(0.75, (float) $map->markers[0]['y'], 0.0001);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/image-maps?format=html", [
                'image_asset_id' => $asset->id,
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/image-maps");

        $this->assertSame([], ImageMap::latest('id')->firstOrFail()->markers);
    }

    public function test_html_marker_add_persists_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = $this->novelFor($universe, $user);
        $asset = $this->imageFor($universe, $user);
        $place = $this->placeFor($universe, 'Puerto');

        $map = ImageMap::create([
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'image_asset_id' => $asset->id,
            'markers' => [],
        ]);

        $this->asWriter($user)
            ->post("/workspace/image-maps/{$map->id}/markers?format=html", [
                'place_card_id' => $place->id,
                'x' => '0.4',
                'y' => '0.6',
                'label' => 'Puerto',
                'note' => 'Muelle viejo.',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/image-maps")
            ->assertSessionHas('status');

        $this->assertCount(1, $map->refresh()->markers);
        $this->assertSame($place->id, (int) $map->markers[0]['place_card_id']);
        $this->assertEqualsWithDelta(0.4, (float) $map->markers[0]['x'], 0.0001);
        $this->assertEqualsWithDelta(0.6, (float) $map->markers[0]['y'], 0.0001);
    }

    public function test_json_contracts_remain_unchanged(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $asset = $this->imageFor($universe, $user);
        $place = $this->placeFor($universe, 'Puerto');

        $map = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset->id,
                'markers' => [['place_card_id' => $place->id, 'x' => 0.25, 'y' => 0.5]],
            ])
            ->assertCreated()
            ->assertJsonPath('image_map.markers.0.place_card_id', $place->id)
            ->json('image_map');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/image-maps")
            ->assertOk()
            ->assertJsonCount(1, 'image_maps');

        $secondPlace = $this->placeFor($universe, 'Faro');

        $this->asWriter($user)
            ->postJson("/workspace/image-maps/{$map['id']}/markers", [
                'place_card_id' => $secondPlace->id,
                'x' => 0.8,
                'y' => 0.2,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'image_map.markers');
    }

    public function test_foreign_place_foreign_asset_and_out_of_range_coordinates_are_rejected(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $asset = $this->imageFor($universe, $user);

        $stranger = $this->writer();
        $foreignUniverse = $this->universeFor($stranger);
        $foreignPlace = $this->placeFor($foreignUniverse, 'Ajeno');
        $foreignAsset = $this->imageFor($foreignUniverse, $stranger, 'ajeno.png');

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset->id,
                'markers' => [['place_card_id' => $foreignPlace->id, 'x' => 0.1, 'y' => 0.1]],
            ])
            ->assertStatus(422);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset->id,
                'markers' => [['place_card_id' => $foreignPlace->id, 'x' => 1.2, 'y' => 0.1]],
            ])
            ->assertStatus(422);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $foreignAsset->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, ImageMap::count());
    }
}
