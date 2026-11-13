<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Services\ContentSearch;
use App\Services\CreativeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CollaborationReadController extends Controller
{
    public function novel(Request $request, Novel $novel)
    {
        $this->requireRead($request, 'novel', $novel->id);

        return response()->json(['novel' => $novel->only(['id', 'title', 'revision'])]);
    }

    public function chapters(Request $request, Novel $novel)
    {
        $user = $request->user();
        $ids = CreativeAccess::accessibleChapterIds($user, $novel);

        abort_unless(CreativeAccess::allows($user, 'novel', $novel->id, 'read') || $ids !== [], 404);

        return response()->json([
            'chapters' => Chapter::whereIn('id', $ids)
                ->orderBy('position')
                ->get(['id', 'title', 'position', 'revision']),
        ]);
    }

    public function chapter(Request $request, Novel $novel, Chapter $chapter)
    {
        abort_unless((int) $chapter->novel_id === (int) $novel->id, 404);

        $this->requireRead($request, 'chapter', $chapter->id);

        return response()->json([
            'chapter' => $chapter->only(['id', 'title', 'position', 'revision', 'plan']),
        ]);
    }

    public function card(Request $request, Card $card)
    {
        $this->requireRead($request, 'card', $card->id);

        return response()->json(['card' => $card]);
    }

    public function search(Request $request, Novel $novel, ContentSearch $search)
    {
        $user = $request->user();

        abort_unless(CreativeAccess::canReadProject($user, $novel), 404);

        $data = $request->validate(ContentSearch::rules());
        $ids = array_map('intval', CreativeAccess::accessibleChapterIds($user, $novel));

        return response()->json($search->search(collect([$novel]), $data, $ids, false));
    }

    public function history(Request $request, Novel $novel)
    {
        $user = $request->user();

        abort_unless(CreativeAccess::canReadProject($user, $novel), 404);

        $ids = CreativeAccess::accessibleChapterIds($user, $novel);

        return response()->json([
            'history' => Chapter::whereIn('id', $ids)
                ->orderBy('position')
                ->get(['id', 'title', 'revision', 'updated_at']),
        ]);
    }

    public function export(Request $request, Novel $novel)
    {
        $user = $request->user();

        abort_unless(CreativeAccess::canReadProject($user, $novel), 404);
        abort_unless(CreativeAccess::allows($user, 'novel', $novel->id, 'download'), 403);

        return response()->json([
            'manifest' => [
                'novel_id' => $novel->id,
                'revision' => $novel->revision,
                'chapters' => Chapter::where('novel_id', $novel->id)
                    ->orderBy('position')
                    ->get(['id', 'title', 'revision']),
            ],
        ]);
    }

    public function download(Request $request, Novel $novel, MediaAsset $asset)
    {
        abort_unless((int) $asset->novel_id === (int) $novel->id, 404);

        $user = $request->user();

        abort_unless(CreativeAccess::canReadProject($user, $novel), 404);
        abort_unless(CreativeAccess::allows($user, 'novel', $novel->id, 'download'), 403);
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return Storage::disk('local')->download($asset->path, $asset->original_name, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function requireRead(Request $request, string $type, int $id): void
    {
        abort_unless(CreativeAccess::allows($request->user(), $type, $id, 'read'), 404);
    }
}
