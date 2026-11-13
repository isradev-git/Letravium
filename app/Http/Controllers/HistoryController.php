<?php

namespace App\Http\Controllers;

use App\Exceptions\RevisionConflict;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Services\ContentAssets;
use App\Services\ContentDocument;
use App\Services\CreativeAccess;
use App\Services\HistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    public function saveScene(Request $request, Scene $scene, HistoryService $history, ContentDocument $documents, ContentAssets $assets): JsonResponse
    {
        $this->authorizeSceneEdit($request, $scene);

        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'operation_id' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'array'],
        ]);
        $data['content'] = $documents->validate($data['content']);
        $assets->assertOwnedBy($data['content'], $request->user()->id, $assets->assetIds($scene->content ?? []));

        try {
            $saved = $history->saveScene($scene, $data['content'], (int) $data['base_revision'], $request->user()->id);
        } catch (RevisionConflict $conflict) {
            return $this->conflict((int) $data['base_revision'], $conflict);
        }

        return response()->json([
            'scene' => $saved,
            'operation_id' => $data['operation_id'] ?? null,
        ]);
    }

    public function saveChapter(Request $request, Chapter $chapter, HistoryService $history, ContentDocument $documents, ContentAssets $assets): JsonResponse
    {
        $this->ownChapter($request, $chapter);

        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'scenes' => ['required', 'array', 'min:1'],
            'scenes.*.id' => ['required', 'integer'],
            'scenes.*.base_revision' => ['required', 'integer', 'min:1'],
            'scenes.*.content' => ['required', 'array'],
        ]);

        foreach ($data['scenes'] as $index => $item) {
            $data['scenes'][$index]['content'] = $documents->validate($item['content']);
            $existing = Scene::find((int) $item['id']);
            $assets->assertOwnedBy($data['scenes'][$index]['content'], $request->user()->id, $existing ? $assets->assetIds($existing->content ?? []) : []);
        }

        try {
            $chapter = $history->saveChapter($chapter, $data['scenes'], (int) $data['base_revision'], $request->user()->id);
        } catch (RevisionConflict $conflict) {
            return $this->conflict((int) $data['base_revision'], $conflict);
        }

        return response()->json(['chapter' => $chapter->refresh()]);
    }

    public function storePendingEdit(Request $request, Scene $scene, ContentDocument $documents, ContentAssets $assets): JsonResponse
    {
        $this->ownScene($request, $scene);

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'base_revision' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'array'],
        ]);
        $data['content'] = $documents->validate($data['content']);
        $assets->assertOwnedBy($data['content'], $request->user()->id, $assets->assetIds($scene->content ?? []));

        $key = [
            'user_id' => $request->user()->id,
            'scene_id' => $scene->id,
            'client_id' => $data['client_id'],
        ];

        DB::table('pending_edits')->updateOrInsert($key, [
            'base_revision' => $data['base_revision'],
            'content' => json_encode($data['content']),
            'status' => 'pending',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json(['pending_edit' => $this->pendingRow($key)], 201);
    }

    public function pendingEdits(Request $request, Scene $scene): JsonResponse
    {
        $this->ownScene($request, $scene);

        $rows = DB::table('pending_edits')
            ->where('user_id', $request->user()->id)
            ->where('scene_id', $scene->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->pendingPayload($row))
            ->all();

        return response()->json(['pending_edits' => $rows]);
    }

    public function revisions(Request $request, Scene $scene)
    {
        $this->ownScene($request, $scene);

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $page = DB::table('content_revisions')
            ->where('scene_id', $scene->id)
            ->orderBy('id')
            ->cursorPaginate($limit);

        $rows = collect($page->items())->map(fn ($row) => [
            'id' => $row->id,
            'scene_id' => $row->scene_id,
            'revision' => (int) $row->revision,
            'content' => json_decode($row->content, true),
            'author_id' => (int) $row->author_id,
            'reason' => $row->reason,
            'created_at' => $row->created_at,
        ])->all();

        $nextCursor = $page->nextCursor()?->encode();

        if ($this->wantsHtml($request)) {
            $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
            $chapter = Chapter::findOrFail($alternative->chapter_id);
            $novel = Novel::findOrFail($chapter->novel_id);

            return view('workspace.scene-history', [
                'scene' => $scene,
                'chapter' => $chapter,
                'novel' => $novel,
                'revisions' => $rows,
                'next_cursor' => $nextCursor,
            ]);
        }

        return response()->json([
            'revisions' => $rows,
            'next_cursor' => $nextCursor,
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function splitScene(Request $request, Scene $scene, HistoryService $history)
    {
        $this->ownScene($request, $scene);

        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'block_index' => ['required', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $result = $history->splitScene($scene, (int) $data['block_index'], (int) $data['base_revision'], $request->user()->id);
        } catch (RevisionConflict $conflict) {
            return $this->conflict((int) $data['base_revision'], $conflict);
        }

        if (! empty($data['title'])) {
            $result['created_scene']->forceFill(['title' => $data['title']])->save();
            $result['created_scene']->refresh();
        }

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/scenes/{$scene->id}/revisions")
                ->with('status', 'Escena dividida.');
        }

        return response()->json($result);
    }

    public function mergeScene(Request $request, Scene $scene, HistoryService $history)
    {
        $this->ownScene($request, $scene);

        $data = $request->validate(['base_revision' => ['required', 'integer', 'min:1']]);

        try {
            $merged = $history->mergeScene($scene, (int) $data['base_revision'], $request->user()->id);
        } catch (RevisionConflict $conflict) {
            return $this->conflict((int) $data['base_revision'], $conflict);
        }

        if ($this->wantsHtml($request)) {
            return redirect("/workspace/scenes/{$scene->id}/revisions")
                ->with('status', 'Escenas fusionadas.');
        }

        return response()->json(['scene' => $merged]);
    }

    private function conflict(int $baseRevision, RevisionConflict $conflict): JsonResponse
    {
        return response()->json([
            'conflict' => true,
            'base_revision' => $baseRevision,
            'current_revision' => $conflict->currentRevision,
            'scene_id' => $conflict->sceneId,
        ], 409);
    }

    private function pendingRow(array $key): array
    {
        return $this->pendingPayload(DB::table('pending_edits')->where($key)->first());
    }

    private function pendingPayload(object $row): array
    {
        return [
            'id' => $row->id,
            'client_id' => $row->client_id,
            'scene_id' => $row->scene_id,
            'base_revision' => (int) $row->base_revision,
            'content' => json_decode($row->content, true),
            'status' => $row->status,
        ];
    }

    private function authorizeSceneEdit(Request $request, Scene $scene): void
    {
        $user = $request->user();
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $novel = Novel::findOrFail($chapter->novel_id);

        if ((int) $novel->owner_id === (int) $user->id) {
            abort_if(EditLease::blocksUser('chapter', (int) $chapter->id, (int) $user->id), 409);

            return;
        }

        if (! CreativeAccess::allows($user, 'chapter', (int) $chapter->id, 'edit')) {
            abort_if(CreativeAccess::allows($user, 'chapter', (int) $chapter->id, 'read'), 403);
            abort(404);
        }

        $lease = EditLease::where('resource_type', 'chapter')
            ->where('resource_id', $chapter->id)
            ->first();

        abort_unless(
            $lease
                && (int) $lease->user_id === (int) $user->id
                && $lease->expires_at?->isFuture(),
            409,
        );
    }

    private function wantsHtml(Request $request): bool
    {
        return ! $request->expectsJson() || $request->query('format') === 'html';
    }

    private function ownScene(Request $request, Scene $scene): void
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $this->ownChapter($request, Chapter::findOrFail($alternative->chapter_id));
    }

    private function ownChapter(Request $request, Chapter $chapter): void
    {
        $novel = Novel::findOrFail($chapter->novel_id);
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
