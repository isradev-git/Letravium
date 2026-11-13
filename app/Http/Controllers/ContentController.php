<?php

namespace App\Http\Controllers;

use App\Exceptions\RevisionConflict;
use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Part;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\WorkState;
use App\Services\ContentAssets;
use App\Services\ContentDocument;
use App\Services\HistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentController extends Controller
{
    public function chapters(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        return response()->json([
            'chapters' => $novel->load(['parts', 'workStates', 'chapters.alternatives.scenes'])->chapters,
            'parts' => $novel->parts,
            'work_states' => $novel->workStates,
        ]);
    }

    public function manuscript(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $data['limit'] ?? 10;
        $query = $novel->chapters();

        if (isset($data['cursor'])) {
            $query->where('position', '>=', $data['cursor']);
        }

        $chapters = $query->limit($limit + 1)->get();
        $hasMore = $chapters->count() > $limit;
        $chapters = $chapters->take($limit)->values();
        $last = $chapters->last();

        return response()->json([
            'novel' => ['id' => $novel->id, 'title' => $novel->title],
            'chapters' => $chapters->map(fn (Chapter $chapter) => $this->chapterPayload($chapter))->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last ? (int) $last->position + 1 : null,
        ]);
    }

    public function chapterView(Request $request, Chapter $chapter)
    {
        $novel = Novel::findOrFail($chapter->novel_id);
        $this->ownNovel($request, $novel);

        return response()->json(['chapter' => $this->chapterPayload($chapter)]);
    }

    private function chapterPayload(Chapter $chapter): array
    {
        $active = $chapter->alternatives()->where('is_active', true)->first();
        $scenes = [];

        if ($active) {
            $scenes = $active->scenes()
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Scene $scene) => [
                    'id' => $scene->id,
                    'title' => $scene->title,
                    'position' => (int) $scene->position,
                    'revision' => (int) $scene->revision,
                    'summary' => $scene->summary,
                    'content' => $scene->content,
                ])->all();
        }

        return [
            'id' => $chapter->id,
            'title' => $chapter->title,
            'position' => (int) $chapter->position,
            'part_id' => $chapter->part_id,
            'revision' => (int) $chapter->revision,
            'active_alternative_id' => $active?->id,
            'scenes' => $scenes,
        ];
    }

    public function storePart(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);

        $part = Part::create([
            'novel_id' => $novel->id,
            'title' => $data['title'],
            'position' => (int) $novel->parts()->max('position') + 1,
        ]);

        if (! $request->expectsJson() || $this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['part' => $part], 201);
    }

    public function storeChapter(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'part_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['part_id'])) {
            abort_unless($novel->parts()->whereKey($data['part_id'])->exists(), 422);
        }

        $chapter = DB::transaction(function () use ($novel, $data) {
            $chapter = Chapter::create([
                'novel_id' => $novel->id,
                'part_id' => $data['part_id'] ?? null,
                'title' => $data['title'] ?? null,
                'position' => (int) $novel->chapters()->max('position') + 1,
            ]);

            ChapterAlternative::create([
                'chapter_id' => $chapter->id,
                'alias' => 'Principal',
                'is_active' => true,
            ]);

            return $chapter;
        });

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}");
        }

        return response()->json(['chapter' => $chapter->load('alternatives')], 201);
    }

    public function reorderChapters(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $ids = $novel->chapters()->pluck('id')->all();
        abort_unless(count($ids) === count($data['order'])
            && array_diff($ids, $data['order']) === []
            && array_diff($data['order'], $ids) === [], 422);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $position => $id) {
                Chapter::whereKey($id)->update(['position' => $position]);
            }
        });

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['status' => 'reordered']);
    }

    public function storeAlternative(Request $request, Chapter $chapter)
    {
        $this->ownChapter($request, $chapter);
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:160'],
            'active' => ['boolean'],
            'plan' => ['nullable', 'array'],
        ]);

        $alternative = DB::transaction(function () use ($chapter, $data) {
            if (($data['active'] ?? false) === true) {
                $chapter->alternatives()->update(['is_active' => false]);
            }

            return ChapterAlternative::create([
                'chapter_id' => $chapter->id,
                'alias' => $data['alias'],
                'is_active' => (bool) ($data['active'] ?? false),
                'plan' => $data['plan'] ?? null,
            ]);
        });

        if (! $request->expectsJson() || $this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$chapter->novel_id}/planning");
        }

        return response()->json(['alternative' => $alternative], 201);
    }

    public function activateAlternative(Request $request, ChapterAlternative $alternative)
    {
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $this->ownChapter($request, $chapter);

        DB::transaction(function () use ($chapter, $alternative) {
            $chapter->alternatives()->update(['is_active' => false]);
            $alternative->forceFill(['is_active' => true])->save();
        });

        if (! $request->expectsJson() || $this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$chapter->novel_id}/planning");
        }

        return response()->json(['alternative' => $alternative->refresh()]);
    }

    public function storeScene(Request $request, ChapterAlternative $alternative, ContentDocument $documents)
    {
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $this->ownChapter($request, $chapter);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'summary' => ['nullable', 'string'],
            'pov_card_id' => ['nullable', 'integer'],
            'place_card_id' => ['nullable', 'integer'],
            'narrative_moment' => ['nullable', 'string', 'max:200'],
            'plan' => ['nullable', 'array'],
            'content' => ['nullable', 'array'],
        ]);

        if (isset($data['content'])) {
            $data['content'] = $documents->validate($data['content']);
            $this->assertOwnedAssets($data['content'], $request);
        }

        $scene = Scene::create($data + [
            'chapter_alternative_id' => $alternative->id,
            'position' => (int) $alternative->scenes()->max('position') + 1,
        ]);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$chapter->novel_id}");
        }

        return response()->json(['scene' => $scene], 201);
    }

    public function updateScene(Request $request, Scene $scene, ContentDocument $documents, HistoryService $history)
    {
        $this->ownScene($request, $scene);

        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        abort_if(EditLease::blocksUser('chapter', (int) $alternative->chapter_id, (int) $request->user()->id), 409);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'summary' => ['nullable', 'string'],
            'pov_card_id' => ['nullable', 'integer'],
            'place_card_id' => ['nullable', 'integer'],
            'narrative_moment' => ['nullable', 'string', 'max:200'],
            'plan' => ['nullable', 'array'],
            'content' => ['nullable', 'array'],
            'base_revision' => ['nullable', 'integer', 'min:1'],
        ]);

        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $novel = Novel::findOrFail($chapter->novel_id);

        foreach ([['pov_card_id', 'characters'], ['place_card_id', 'places']] as [$field, $area]) {
            if (! empty($data[$field])) {
                abort_unless(
                    Card::whereKey($data[$field])
                        ->where('universe_id', $novel->universe_id)
                        ->where('area', $area)
                        ->exists(),
                    422,
                );
            }
        }

        if (array_key_exists('content', $data)) {
            if (! is_array($data['content'])) {
                throw ValidationException::withMessages(['content' => 'El contenido de la escena debe ser un documento.']);
            }

            $data['content'] = $documents->validate($data['content']);
            $this->assertOwnedAssets($data['content'], $request, app(ContentAssets::class)->assetIds($scene->content ?? []));

            try {
                $scene = $history->saveScene(
                    $scene,
                    $data['content'],
                    (int) ($data['base_revision'] ?? $scene->revision),
                    (int) $request->user()->id,
                );
            } catch (RevisionConflict $conflict) {
                return response()->json([
                    'message' => 'La escena cambió en otra sesión.',
                    'current_revision' => $conflict->currentRevision,
                ], 409);
            }
        }

        $metadata = array_intersect_key($data, array_flip([
            'title', 'summary', 'pov_card_id', 'place_card_id', 'narrative_moment', 'plan',
        ]));

        if (array_key_exists('plan', $metadata) && $this->wantsHtml($request)) {
            $metadata['plan'] = array_merge($scene->plan ?? [], $metadata['plan']);
        }

        if ($metadata !== []) {
            $scene->forceFill($metadata)->save();
        }

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['scene' => $scene->refresh()]);
    }

    public function updateChapterPlan(Request $request, Chapter $chapter)
    {
        $this->ownChapter($request, $chapter);
        $data = $request->validate(['plan' => ['required', 'array']]);
        $plan = $this->wantsHtml($request)
            ? array_merge($chapter->plan ?? [], $data['plan'])
            : $data['plan'];
        $chapter->forceFill(['plan' => $plan])->save();

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$chapter->novel_id}/planning");
        }

        return response()->json(['chapter' => $chapter->refresh()]);
    }

    public function updateAlternativePlan(Request $request, ChapterAlternative $alternative)
    {
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $this->ownChapter($request, $chapter);
        $data = $request->validate(['plan' => ['required', 'array']]);
        $plan = $this->wantsHtml($request)
            ? array_merge($alternative->plan ?? [], $data['plan'])
            : $data['plan'];
        $alternative->forceFill(['plan' => $plan])->save();

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$chapter->novel_id}/planning");
        }

        return response()->json(['alternative' => $alternative->refresh()]);
    }

    public function storePlot(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'array'],
        ]);

        $plot = Plot::create($data + ['novel_id' => $novel->id]);

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['plot' => $plot], 201);
    }

    public function attachPlot(Request $request, Plot $plot, Scene $scene)
    {
        $novel = Novel::findOrFail($plot->novel_id);
        $this->ownNovel($request, $novel);
        $this->ownScene($request, $scene);
        $this->assertSceneInNovel($scene, $novel);

        $plot->scenes()->syncWithoutDetaching([$scene->id]);

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['plot' => $plot->load('scenes')]);
    }

    public function attachScenePlot(Request $request, Scene $scene)
    {
        $this->ownScene($request, $scene);
        $data = $request->validate(['plot_id' => ['required', 'integer']]);
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $novel = Novel::findOrFail(Chapter::findOrFail($alternative->chapter_id)->novel_id);
        $plot = Plot::whereKey($data['plot_id'])->where('novel_id', $novel->id)->firstOrFail();
        $plot->scenes()->syncWithoutDetaching([$scene->id]);

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['plot' => $plot->load('scenes')]);
    }

    public function storeWorkState(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $state = WorkState::create([
            'novel_id' => $novel->id,
            'name' => $data['name'],
            'position' => (int) $novel->workStates()->max('position') + 1,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['work_state' => $state], 201);
    }

    public function updateWorkState(Request $request, WorkState $state)
    {
        $this->ownNovel($request, Novel::findOrFail($state->novel_id));
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $state->forceFill(['name' => $data['name']])->save();

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$state->novel_id}/planning");
        }

        return response()->json(['work_state' => $state->refresh()]);
    }

    public function assignWorkState(Request $request, ChapterAlternative $alternative)
    {
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $this->ownChapter($request, $chapter);
        $data = $request->validate(['work_state_id' => ['nullable', 'integer']]);

        if (! empty($data['work_state_id'])) {
            abort_unless(
                WorkState::whereKey($data['work_state_id'])
                    ->where('novel_id', $chapter->novel_id)->exists(),
                422,
            );
        }

        $alternative->forceFill(['work_state_id' => $data['work_state_id'] ?? null])->save();

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$chapter->novel_id}/planning");
        }

        return response()->json(['alternative' => $alternative->refresh()]);
    }

    public function wordCount(Request $request, Novel $novel, ContentDocument $documents)
    {
        $this->ownNovel($request, $novel);

        $chapters = [];
        $total = 0;

        foreach ($novel->chapters as $chapter) {
            $active = $chapter->alternatives()->where('is_active', true)->first();
            $count = 0;

            if ($active) {
                foreach ($active->scenes as $scene) {
                    $count += $documents->wordCount($scene->content ?? []);
                }
            }

            $chapters[] = ['chapter_id' => $chapter->id, 'words' => $count];
            $total += $count;
        }

        return response()->json([
            'total' => $total,
            'chapters' => $chapters,
            'target' => DB::table('novel_goals')->where('novel_id', $novel->id)->value('target_words'),
        ]);
    }

    public function setGoal(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate(['target_words' => ['required', 'integer', 'min:0']]);

        DB::table('novel_goals')->updateOrInsert(
            ['novel_id' => $novel->id],
            ['target_words' => $data['target_words'], 'updated_at' => now(), 'created_at' => now()],
        );

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/novels/{$novel->id}/planning");
        }

        return response()->json(['target_words' => $data['target_words']]);
    }

    private function assertOwnedAssets(array $content, Request $request, array $allowedIds = []): void
    {
        app(ContentAssets::class)->assertOwnedBy($content, $request->user()->id, $allowedIds);
    }

    private function assertSceneInNovel(Scene $scene, Novel $novel): void
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $sceneNovelId = Chapter::findOrFail($alternative->chapter_id)->novel_id;

        abort_unless((int) $sceneNovelId === (int) $novel->id, 404);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }

    private function ownChapter(Request $request, Chapter $chapter): void
    {
        $this->ownNovel($request, Novel::findOrFail($chapter->novel_id));
    }

    private function ownScene(Request $request, Scene $scene): void
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $this->ownChapter($request, Chapter::findOrFail($alternative->chapter_id));
    }
}
