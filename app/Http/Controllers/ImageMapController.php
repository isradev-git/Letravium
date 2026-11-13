<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\ImageMap;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImageMapController extends Controller
{
    public function indexUniverse(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $maps = ImageMap::where('universe_id', $universe->id)
            ->with('asset')->orderBy('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['image_maps' => $maps]);
        }

        return view('workspace.image-maps', $this->viewData($request, $universe, null, $maps));
    }

    public function storeUniverse(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        return $this->createMap($request, $universe, null);
    }

    public function indexNovel(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $maps = ImageMap::where('novel_id', $novel->id)
            ->with('asset')->orderBy('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['image_maps' => $maps]);
        }

        $universe = Universe::findOrFail($novel->universe_id);

        return view('workspace.image-maps', $this->viewData($request, $universe, $novel, $maps));
    }

    public function storeNovel(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $universe = Universe::findOrFail($novel->universe_id);
        $this->ownUniverse($request, $universe);

        return $this->createMap($request, $universe, $novel);
    }

    public function update(Request $request, ImageMap $map)
    {
        $universe = $this->ownedMapUniverse($request, $map);

        $markers = $this->validatedMarkers($request, $universe);

        $map->forceFill(['markers' => $markers])->save();

        return response()->json(['image_map' => $map->refresh()->load('asset')]);
    }

    public function storeMarker(Request $request, ImageMap $map)
    {
        $universe = $this->ownedMapUniverse($request, $map);

        $marker = $request->validate($this->markerRules(null));
        $markers = $this->validateMarkerList([...($map->markers ?? []), $marker], $universe);

        $map->forceFill(['markers' => $markers])->save();

        if ($this->wantsHtml($request)) {
            return redirect($this->mapPath($map))->with('status', 'Marcador añadido al mapa.');
        }

        return response()->json(['image_map' => $map->refresh()->load('asset')], 201);
    }

    private function createMap(Request $request, Universe $universe, ?Novel $novel)
    {
        $data = $request->validate(['image_asset_id' => ['required', 'integer']]);
        $asset = MediaAsset::whereKey($data['image_asset_id'])
            ->where('owner_id', $request->user()->id)
            ->where('kind', 'image')
            ->firstOrFail();

        if ($this->wantsHtml($request)) {
            $request->merge([
                'markers' => $request->filled('place_card_id') ? [[
                    'place_card_id' => $request->input('place_card_id'),
                    'x' => $request->input('x'),
                    'y' => $request->input('y'),
                    'label' => $request->input('label'),
                    'note' => $request->input('note'),
                ]] : [],
            ]);
        }

        $markers = $this->validatedMarkers($request, $universe);

        $map = ImageMap::create([
            'universe_id' => $universe->id,
            'novel_id' => $novel?->id,
            'image_asset_id' => $asset->id,
            'markers' => $markers,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect($this->mapPath($map))->with('status', 'Mapa creado.');
        }

        return response()->json(['image_map' => $map->load('asset')], 201);
    }

    private function validatedMarkers(Request $request, Universe $universe): array
    {
        $data = $request->validate(['markers' => ['nullable', 'array']] + $this->markerRules());

        return $this->validateMarkerList($data['markers'] ?? [], $universe);
    }

    private function markerRules(?string $prefix = 'markers.*'): array
    {
        $path = $prefix === null ? '' : $prefix.'.';

        return [
            $path.'place_card_id' => ['required', 'integer'],
            $path.'x' => ['required', 'numeric', 'between:0,1'],
            $path.'y' => ['required', 'numeric', 'between:0,1'],
            $path.'label' => ['nullable', 'string', 'max:200'],
            $path.'note' => ['nullable', 'string', 'max:2000'],
            $path.'route_note' => ['nullable', 'string', 'max:2000'],
            $path.'distance_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function validateMarkerList(array $markers, Universe $universe): array
    {
        $seen = [];

        foreach ($markers as $index => $marker) {
            $cardId = (int) $marker['place_card_id'];

            if (isset($seen[$cardId])) {
                throw ValidationException::withMessages([
                    "markers.{$index}.place_card_id" => 'El lugar ya tiene un marcador en este mapa.',
                ]);
            }

            $exists = Card::whereKey($cardId)
                ->where('universe_id', $universe->id)
                ->where('area', 'places')
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    "markers.{$index}.place_card_id" => 'El marcador debe apuntar a una ficha de lugar del mismo universo.',
                ]);
            }

            $seen[$cardId] = true;
        }

        return $markers;
    }

    private function ownedMapUniverse(Request $request, ImageMap $map): Universe
    {
        if ($map->universe_id) {
            $universe = Universe::findOrFail($map->universe_id);
            $this->ownUniverse($request, $universe);

            return $universe;
        }

        if ($map->novel_id) {
            $novel = Novel::findOrFail($map->novel_id);
            $this->ownNovel($request, $novel);

            return Universe::findOrFail($novel->universe_id);
        }

        abort(404);
    }

    private function viewData(Request $request, Universe $universe, ?Novel $novel, $maps): array
    {
        return [
            'universe' => $universe,
            'novel' => $novel,
            'maps' => $maps,
            'assets' => MediaAsset::where('owner_id', $request->user()->id)
                ->where('kind', 'image')->orderBy('original_name')->get(),
            'places' => Card::where('universe_id', $universe->id)
                ->where('area', 'places')->orderBy('name')->get(),
        ];
    }

    private function mapPath(ImageMap $map): string
    {
        return $map->novel_id
            ? '/workspace/novels/'.$map->novel_id.'/image-maps'
            : '/workspace/universes/'.$map->universe_id.'/image-maps';
    }

    private function wantsHtml(Request $request): bool
    {
        return ! $request->expectsJson() || $request->query('format') === 'html';
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
