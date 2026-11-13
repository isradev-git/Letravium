<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Collection;
use App\Models\CreativePermission;
use App\Models\Novel;
use App\Models\Universe;
use App\Services\CreativeAccess;
use App\Services\NovelDuplicator;
use App\Services\UniverseDuplicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['area' => 'creative']);
        }

        $universes = Universe::where('owner_id', $request->user()->id)
            ->with(['novels' => fn ($query) => $query
                ->where('owner_id', $request->user()->id)
                ->whereNull('archived_at')
                ->orderBy('id')])
            ->orderBy('id')
            ->get();

        $grants = CreativePermission::where('user_id', $request->user()->id)
            ->whereIn('resource_type', ['novel', 'chapter'])
            ->get();
        $sharedNovelIds = [];

        foreach ($grants as $grant) {
            if ($grant->resource_type === 'novel') {
                $sharedNovelIds[] = (int) $grant->resource_id;

                continue;
            }

            $novelId = Chapter::whereKey($grant->resource_id)->value('novel_id');

            if ($novelId !== null) {
                $sharedNovelIds[] = (int) $novelId;
            }
        }

        $shared = Novel::whereIn('id', array_values(array_unique($sharedNovelIds)))
            ->where('owner_id', '!=', $request->user()->id)
            ->orderBy('id')
            ->get()
            ->map(function (Novel $novel) use ($request) {
                $chapterIds = CreativeAccess::accessibleChapterIds($request->user(), $novel);

                return [
                    'novel' => $novel,
                    'chapters' => Chapter::whereIn('id', $chapterIds)
                        ->orderBy('position')
                        ->get(['id', 'title', 'position']),
                ];
            })
            ->filter(fn (array $entry) => $entry['chapters']->isNotEmpty())
            ->values();

        $activeNovels = $universes->flatMap->novels;
        $archivedNovels = Novel::where('owner_id', $request->user()->id)
            ->whereNotNull('archived_at')
            ->with('universe')
            ->orderByDesc('archived_at')
            ->get();
        $collections = Collection::where('owner_id', $request->user()->id)
            ->with('novels')
            ->orderBy('id')
            ->get();

        return view('workspace.index', [
            'universes' => $universes,
            'shared' => $shared,
            'activeNovels' => $activeNovels,
            'archivedNovels' => $archivedNovels,
            'collections' => $collections,
        ]);
    }

    public function universes(Request $request)
    {
        return response()->json([
            'universes' => Universe::where('owner_id', $request->user()->id)
                ->withCount('novels')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function storeUniverse(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
        ]);

        $universe = Universe::create($data + ['owner_id' => $request->user()->id]);

        if (! $request->expectsJson()) {
            return redirect('/workspace');
        }

        return response()->json(['universe' => $universe], 201);
    }

    public function storeNovel(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'settings' => ['nullable', 'array'],
        ]);

        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $request->user()->id,
            'title' => $data['title'],
            'settings' => $data['settings'] ?? null,
        ]);

        if (! $request->expectsJson()) {
            return redirect('/workspace');
        }

        return response()->json(['novel' => $novel], 201);
    }

    public function updateNovel(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);
        $novel->update($data);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Título actualizado.');
        }

        return response()->json(['novel' => $novel->refresh()]);
    }

    public function updateUniverse(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
        ]);
        $universe->update($data);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Universo actualizado.');
        }

        return response()->json(['universe' => $universe->refresh()]);
    }

    public function archiveNovel(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $novel->forceFill(['archived_at' => now()])->save();

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Novela archivada.');
        }

        return response()->json(['novel' => $novel->refresh()]);
    }

    public function unarchiveNovel(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $novel->forceFill(['archived_at' => null])->save();

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Novela restaurada.');
        }

        return response()->json(['novel' => $novel->refresh()]);
    }

    public function duplicateNovel(Request $request, Novel $novel, NovelDuplicator $duplicator)
    {
        $this->ownNovel($request, $novel);
        $copy = $duplicator->duplicate($novel, $request->user()->id);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Copia creada: '.$copy->title.'.');
        }

        return response()->json(['novel' => $copy], 201);
    }

    public function duplicateUniverse(Request $request, Universe $universe, UniverseDuplicator $duplicator)
    {
        $this->ownUniverse($request, $universe);
        $copy = $duplicator->duplicate($universe, $request->user()->id);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Universo copiado: '.$copy->name.'.');
        }

        return response()->json(['universe' => $copy], 201);
    }

    public function storeCollection(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);

        $collection = Collection::create($data + ['owner_id' => $request->user()->id]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Colección creada.');
        }

        return response()->json(['collection' => $collection], 201);
    }

    public function addNovel(Request $request, Collection $collection, Novel $novel)
    {
        abort_unless($collection->owner_id === $request->user()->id, 404);
        $this->ownNovel($request, $novel);

        if (! $collection->novels()->whereKey($novel->id)->exists()) {
            $position = (int) $collection->novels()->max('collection_novel.position') + 1;
            DB::table('collection_novel')->insertOrIgnore([
                'collection_id' => $collection->id,
                'novel_id' => $novel->id,
                'position' => $position,
            ]);
        }

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Novela añadida a la colección.');
        }

        return response()->json(['collection' => $collection->load('novels')]);
    }

    public function updateCollection(Request $request, Collection $collection)
    {
        $this->ownCollection($request, $collection);
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $collection->update($data);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Colección renombrada.');
        }

        return response()->json(['collection' => $collection]);
    }

    public function destroyCollection(Request $request, Collection $collection)
    {
        $this->ownCollection($request, $collection);

        DB::transaction(function () use ($collection) {
            $collection->novels()->detach();
            $collection->delete();
        });

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Colección eliminada. Sus novelas siguen en la biblioteca.');
        }

        return response()->json(['deleted' => true]);
    }

    public function removeNovel(Request $request, Collection $collection, Novel $novel)
    {
        $this->ownCollection($request, $collection);
        $this->ownNovel($request, $novel);
        abort_unless($collection->novels()->whereKey($novel->id)->exists(), 404);

        $collection->novels()->detach($novel->id);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Novela retirada de la colección.');
        }

        return response()->json(['collection' => $collection->load('novels')]);
    }

    public function moveNovel(Request $request, Collection $collection, Novel $novel)
    {
        $this->ownCollection($request, $collection);
        $this->ownNovel($request, $novel);
        abort_unless($collection->novels()->whereKey($novel->id)->exists(), 404);

        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        $order = $collection->novels()->pluck('novels.id')->all();
        $index = array_search($novel->id, $order, true);
        $target = $data['direction'] === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($order)) {
            $message = 'La novela ya está en el extremo de la colección.';

            if ($this->wantsHtml($request)) {
                return redirect('/workspace')->withErrors(['collection' => $message]);
            }

            return response()->json(['message' => $message], 422);
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        foreach ($order as $position => $id) {
            $collection->novels()->updateExistingPivot($id, ['position' => $position]);
        }

        if ($this->wantsHtml($request)) {
            return redirect('/workspace')->with('status', 'Orden de la colección actualizado.');
        }

        return response()->json([
            'collection' => $collection->load('novels'),
            'order' => $order,
        ]);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }

    private function ownCollection(Request $request, Collection $collection): void
    {
        abort_unless($collection->owner_id === $request->user()->id, 404);
    }
}
