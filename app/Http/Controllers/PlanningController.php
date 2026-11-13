<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Novel;
use App\Models\Plot;
use App\Services\ContentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanningController extends Controller
{
    public function show(Request $request, Novel $novel, ContentDocument $documents)
    {
        abort_unless((int) $novel->owner_id === (int) $request->user()->id, 404);

        $novel->load(['parts', 'workStates', 'chapters.alternatives.scenes']);
        $wordCounts = [];
        $total = 0;

        foreach ($novel->chapters as $chapter) {
            $active = $chapter->alternatives->firstWhere('is_active', true);
            $count = 0;

            foreach ($active?->scenes ?? [] as $scene) {
                $count += $documents->wordCount($scene->content ?? []);
            }

            $wordCounts[$chapter->id] = $count;
            $total += $count;
        }

        $target = DB::table('novel_goals')->where('novel_id', $novel->id)->value('target_words');
        $plots = Plot::where('novel_id', $novel->id)
            ->with(['scenes:id,title'])
            ->orderBy('id')
            ->get();
        $cards = Card::where('universe_id', $novel->universe_id)
            ->whereIn('area', ['characters', 'places'])
            ->orderBy('name')
            ->get(['id', 'area', 'name']);

        return view('workspace.planning', [
            'novel' => $novel,
            'chapters' => $novel->chapters,
            'wordCounts' => $wordCounts,
            'totalWords' => $total,
            'target' => $target === null ? null : (int) $target,
            'workStates' => $novel->workStates,
            'characters' => $cards->where('area', 'characters')->values(),
            'places' => $cards->where('area', 'places')->values(),
            'plots' => $plots,
        ]);
    }
}
