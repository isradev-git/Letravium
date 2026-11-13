<?php

namespace App\Http\Controllers;

use App\Models\BetaReader;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Comment;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\CreativeAccess;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request, Novel $novel)
    {
        $user = $request->user();
        $query = Comment::with(['messages.author', 'author:id,name', 'corrections'])->where('novel_id', $novel->id)->latest('id');

        if ($novel->owner_id !== $user->id) {
            $readers = $this->betaReadersFor($user, $novel->id);
            $chapterIds = CreativeAccess::accessibleChapterIds($user, $novel);

            abort_if($readers->isEmpty() && $chapterIds === [], 404);

            $query->where(function ($query) use ($readers, $chapterIds) {
                if ($readers->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($readers) {
                        $query->whereIn('snapshot_id', $readers->pluck('snapshot_id')->unique()->all());

                        if (! $readers->contains(fn (BetaReader $reader) => $reader->scope === null)) {
                            $chapters = $readers
                                ->flatMap(fn (BetaReader $reader) => $reader->scope['chapters'] ?? [])
                                ->map(fn ($id) => (int) $id)
                                ->unique()
                                ->all();

                            $query->where(function ($query) use ($chapters) {
                                $query->whereNull('chapter_id')->orWhereIn('chapter_id', $chapters);
                            });
                        }
                    });
                }

                if ($chapterIds !== []) {
                    $query->orWhereIn('chapter_id', $chapterIds);
                }
            });
        }

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $page = $query->orderByDesc('id')->cursorPaginate($limit);

        $comments = collect($page->items())->map(fn (Comment $comment) => $this->serialize($comment))->all();
        $nextCursor = $page->nextCursor()?->encode();

        if ($request->expectsJson()) {
            return response()->json([
                'comments' => $comments,
                'next_cursor' => $nextCursor,
                'has_more' => $page->hasMorePages(),
            ]);
        }

        return view('workspace.comments', [
            'novel' => $novel,
            'comments' => $comments,
            'nextCursor' => $nextCursor,
            'isOwner' => $novel->owner_id === $user->id,
        ]);
    }

    public function store(Request $request, Novel $novel)
    {
        $user = $request->user();
        $data = $request->validate([
            'chapter_id' => ['nullable', 'integer'],
            'scene_id' => ['nullable', 'integer'],
            'snapshot_id' => ['nullable', 'integer'],
            'block_id' => ['nullable', 'string', 'max:120'],
            'quoted_text' => ['nullable', 'string'],
            'body' => ['required', 'string'],
        ]);

        $snapshotId = $data['snapshot_id'] ?? null;

        if ($snapshotId !== null) {
            abort_unless(Snapshot::whereKey($snapshotId)->where('novel_id', $novel->id)->exists(), 404);
        }

        $chapterId = $data['chapter_id'] ?? null;

        if (! empty($data['scene_id'])) {
            $scene = Scene::findOrFail($data['scene_id']);
            $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
            $chapterId = (int) Chapter::findOrFail($alternative->chapter_id)->id;

            abort_unless(
                Chapter::whereKey($chapterId)->where('novel_id', $novel->id)->exists(),
                404,
            );
        }

        $beta = null;

        if ($novel->owner_id !== $user->id) {
            if ($snapshotId !== null) {
                $beta = $this->betaReader($user, $novel->id, $snapshotId);
                abort_unless($beta !== null && $beta->isActive(), 404);
                abort_unless($beta->can_comment, 403);
                abort_unless($beta->allowsChapter($chapterId), 403);
            } else {
                abort_if($chapterId === null, 403);
                abort_unless(
                    Chapter::whereKey($chapterId)->where('novel_id', $novel->id)->exists(),
                    404,
                );
                abort_unless(CreativeAccess::allows($user, 'chapter', (int) $chapterId, 'comment'), 403);
            }
        }

        $comment = Comment::create([
            'novel_id' => $novel->id,
            'chapter_id' => $chapterId,
            'scene_id' => $data['scene_id'] ?? null,
            'snapshot_id' => $snapshotId,
            'block_id' => $data['block_id'] ?? null,
            'quoted_text' => $data['quoted_text'] ?? null,
            'status' => 'open',
            'author_id' => $user->id,
        ]);

        $comment->messages()->create([
            'author_id' => $user->id,
            'body' => $data['body'],
        ]);

        return response()->json(['comment' => $this->serialize($comment->refresh())], 201);
    }

    public function addMessage(Request $request, Comment $comment)
    {
        $this->authorizeComment($request, $comment);
        $data = $request->validate(['body' => ['required', 'string']]);

        $message = $comment->messages()->create([
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$comment->novel_id}/comments")->with('status', 'Respuesta añadida.');
        }

        return response()->json(['message' => $message], 201);
    }

    public function update(Request $request, Comment $comment)
    {
        abort_unless($comment->novel->owner_id === $request->user()->id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:open,needs_review,resolved'],
        ]);

        $comment->forceFill(['status' => $data['status']])->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$comment->novel_id}/comments")->with('status', 'Estado del comentario actualizado.');
        }

        return response()->json(['comment' => $this->serialize($comment->refresh())]);
    }

    private function authorizeComment(Request $request, Comment $comment): void
    {
        $user = $request->user();
        $novel = $comment->novel;

        if ($novel->owner_id === $user->id) {
            return;
        }

        $chapterId = $comment->chapter_id === null ? null : (int) $comment->chapter_id;
        $canCollaborate = $chapterId !== null
            && CreativeAccess::allows($user, 'chapter', $chapterId, 'comment');

        if ($comment->snapshot_id === null) {
            abort_unless($canCollaborate, $comment->author_id === $user->id ? 403 : 404);

            return;
        }

        $beta = $this->betaReader($user, $novel->id, $comment->snapshot_id);

        if ($comment->author_id === $user->id) {
            abort_unless($beta !== null && $beta->can_comment && $beta->allowsChapter($chapterId), 403);

            return;
        }

        abort_unless($beta !== null, 404);
        abort_unless($beta->can_comment, 403);
        abort_unless($beta->allowsChapter($chapterId), 403);
    }

    private function serialize(Comment $comment): array
    {
        $comment->load('messages');
        $data = $comment->toArray();

        return $data + ['anchor_status' => $this->anchorStatus($comment)];
    }

    private function anchorStatus(Comment $comment): string
    {
        if ($comment->snapshot_id !== null) {
            return 'anchored';
        }

        if ($comment->scene_id === null) {
            return 'unanchored';
        }

        $scene = Scene::find($comment->scene_id);

        if ($scene === null) {
            return 'needs_review';
        }

        foreach ($scene->content['blocks'] ?? [] as $block) {
            if (($block['id'] ?? null) === $comment->block_id) {
                return ($block['text'] ?? null) === $comment->quoted_text ? 'anchored' : 'needs_review';
            }
        }

        return 'needs_review';
    }

    private function betaReader(User $user, int $novelId, ?int $snapshotId = null): ?BetaReader
    {
        return BetaReader::query()
            ->join('snapshots', 'snapshots.id', '=', 'beta_readers.snapshot_id')
            ->where('snapshots.novel_id', $novelId)
            ->when($snapshotId !== null, fn ($query) => $query->where('beta_readers.snapshot_id', $snapshotId))
            ->where('beta_readers.expires_at', '>', now())
            ->where(function ($query) use ($user) {
                $query->where('beta_readers.user_id', $user->id)
                    ->orWhere(function ($query) use ($user) {
                        $query->whereNull('beta_readers.user_id')
                            ->where('beta_readers.email', $user->email);
                    });
            })
            ->select('beta_readers.*')
            ->first();
    }

    private function betaReadersFor(User $user, int $novelId)
    {
        return BetaReader::query()
            ->join('snapshots', 'snapshots.id', '=', 'beta_readers.snapshot_id')
            ->where('snapshots.novel_id', $novelId)
            ->where('beta_readers.expires_at', '>', now())
            ->where(function ($query) use ($user) {
                $query->where('beta_readers.user_id', $user->id)
                    ->orWhere(function ($query) use ($user) {
                        $query->whereNull('beta_readers.user_id')
                            ->where('beta_readers.email', $user->email);
                    });
            })
            ->select('beta_readers.*')
            ->get();
    }
}
