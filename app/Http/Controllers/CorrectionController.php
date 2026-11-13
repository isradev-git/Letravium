<?php

namespace App\Http\Controllers;

use App\Models\BetaReader;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Comment;
use App\Models\CorrectionProposal;
use App\Models\EditLease;
use App\Models\Scene;
use App\Services\CreativeAccess;
use App\Services\SnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorrectionController extends Controller
{
    public function store(Request $request, Comment $comment)
    {
        $this->authorizeComment($request, $comment);
        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:0'],
            'original' => ['required', 'string'],
            'proposed' => ['required', 'string'],
        ]);

        $proposal = CorrectionProposal::create($data + [
            'comment_id' => $comment->id,
            'status' => CorrectionProposal::PENDING,
        ]);

        return response()->json(['correction' => $proposal], 201);
    }

    public function accept(Request $request, CorrectionProposal $proposal, SnapshotService $snapshots)
    {
        $comment = $proposal->comment;
        $novel = $comment->novel;
        abort_if($comment->scene_id === null, 422, 'Proposal has no scene target.');

        $scene = Scene::findOrFail($comment->scene_id);
        $chapterId = $this->chapterId($scene);

        abort_unless(CreativeAccess::allows($request->user(), 'chapter', $chapterId, 'accept_corrections'), 404);
        abort_if($proposal->status !== CorrectionProposal::PENDING, 422, 'Proposal already resolved.');

        abort_unless(
            EditLease::where('resource_type', 'chapter')
                ->where('resource_id', $chapterId)
                ->where('user_id', $request->user()->id)
                ->where('expires_at', '>', now())
                ->exists(),
            403,
            'lease_required',
        );

        $content = $scene->content ?? [];
        $index = $this->blockIndex($content, $comment->block_id);

        $stale = (int) $scene->revision !== (int) $proposal->base_revision
            || $index === null
            || ($content['blocks'][$index]['text'] ?? null) !== $proposal->original;

        if ($stale) {
            $proposal->forceFill(['status' => CorrectionProposal::STALE])->save();

            if ($request->query('format') === 'html') {
                return redirect("/workspace/novels/{$novel->id}/comments")
                    ->withErrors(['correction' => 'La propuesta ya no coincide con el texto actual.']);
            }

            return response()->json([
                'error' => 'stale_target',
                'current_revision' => (int) $scene->revision,
            ], 409);
        }

        DB::transaction(function () use ($snapshots, $novel, $scene, $proposal, $comment, $content, $index, $request) {
            $snapshots->recordHistory($novel->id, 'scene', $scene, $request->user(), 'correction');

            $content['blocks'][$index]['text'] = $proposal->proposed;
            $scene->forceFill(['content' => $content, 'revision' => (int) $scene->revision + 1])->save();

            $proposal->forceFill(['status' => CorrectionProposal::ACCEPTED])->save();
            $comment->forceFill(['status' => 'resolved'])->save();
        });

        if ($request->query('format') === 'html') {
            return redirect("/workspace/novels/{$novel->id}/comments")->with('status', 'Corrección aceptada.');
        }

        return response()->json([
            'correction' => $proposal->refresh(),
            'scene' => $scene->refresh(),
        ]);
    }

    public function reject(Request $request, CorrectionProposal $proposal)
    {
        $comment = $proposal->comment;
        $chapterId = $comment->chapter_id !== null
            ? (int) $comment->chapter_id
            : ($comment->scene_id !== null ? $this->chapterId(Scene::findOrFail($comment->scene_id)) : null);

        abort_unless(
            $comment->novel->owner_id === $request->user()->id
            || ($chapterId !== null && CreativeAccess::allows($request->user(), 'chapter', $chapterId, 'accept_corrections')),
            404,
        );
        abort_if($proposal->status !== CorrectionProposal::PENDING, 422, 'Proposal already resolved.');

        $proposal->forceFill(['status' => CorrectionProposal::REJECTED])->save();

        if ($request->query('format') === 'html') {
            return redirect("/workspace/novels/{$comment->novel_id}/comments")->with('status', 'Corrección rechazada.');
        }

        return response()->json(['correction' => $proposal->refresh()]);
    }

    private function authorizeComment(Request $request, Comment $comment): void
    {
        $user = $request->user();
        $novel = $comment->novel;

        if ($novel->owner_id === $user->id) {
            return;
        }

        $chapterId = $comment->chapter_id === null ? null : (int) $comment->chapter_id;

        if ($comment->snapshot_id === null) {
            abort_unless(
                $chapterId !== null && CreativeAccess::allows($user, 'chapter', $chapterId, 'comment'),
                404,
            );

            return;
        }

        $beta = BetaReader::query()
            ->join('snapshots', 'snapshots.id', '=', 'beta_readers.snapshot_id')
            ->where('snapshots.novel_id', $novel->id)
            ->where('beta_readers.snapshot_id', $comment->snapshot_id)
            ->where('beta_readers.expires_at', '>', now())
            ->where('beta_readers.can_comment', true)
            ->where(function ($query) use ($user) {
                $query->where('beta_readers.user_id', $user->id)
                    ->orWhere(function ($query) use ($user) {
                        $query->whereNull('beta_readers.user_id')
                            ->where('beta_readers.email', $user->email);
                    });
            })
            ->select('beta_readers.*')
            ->first();

        abort_unless($beta !== null, 404);
        abort_unless($beta->allowsChapter($chapterId), 403);
    }

    private function chapterId(Scene $scene): int
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);

        return (int) Chapter::findOrFail($alternative->chapter_id)->id;
    }

    private function blockIndex(array $content, ?string $blockId): ?int
    {
        foreach ($content['blocks'] ?? [] as $index => $block) {
            if (($block['id'] ?? null) === $blockId) {
                return $index;
            }
        }

        return null;
    }
}
