<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Universe;
use App\Services\ContentSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request, Novel $novel, ContentSearch $search)
    {
        $this->ownNovel($request, $novel);

        if ($request->expectsJson()) {
            $data = $request->validate(ContentSearch::rules());

            return response()->json($search->search(collect([$novel]), $data));
        }

        $query = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $type = in_array($type, ['chapter', 'scene', 'card'], true) ? $type : null;
        $results = null;

        if ($query !== '') {
            $data = $request->validate(ContentSearch::rules());
            $results = $search->search(collect([$novel]), $data);
        }

        return view('workspace.search', [
            'novel' => $novel,
            'query' => $query,
            'type' => $type,
            'results' => $results,
            'chapters' => $novel->chapters()->get(['id', 'title', 'position']),
        ]);
    }

    public function searchChapter(Request $request, Chapter $chapter, ContentSearch $search)
    {
        $novel = Novel::findOrFail($chapter->novel_id);
        $this->ownNovel($request, $novel);

        $data = $request->validate(ContentSearch::rules());

        return response()->json($search->search(collect([$novel]), $data, [(int) $chapter->id], false));
    }

    public function searchUniverse(Request $request, Universe $universe, ContentSearch $search)
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);

        $data = $request->validate(ContentSearch::rules());
        $novels = Novel::where('universe_id', $universe->id)
            ->where('owner_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        return response()->json($search->search($novels, $data));
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
