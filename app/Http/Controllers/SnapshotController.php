<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Services\SnapshotService;
use Illuminate\Http\Request;

class SnapshotController extends Controller
{
    public function index(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $snapshots = Snapshot::where('novel_id', $novel->id)
            ->latest('id')
            ->get(['id', 'novel_id', 'name', 'created_by', 'created_at']);

        if ($request->expectsJson()) {
            return response()->json(['snapshots' => $snapshots]);
        }

        return view('workspace.snapshots', [
            'novel' => $novel,
            'snapshots' => $snapshots,
        ]);
    }

    public function store(Request $request, Novel $novel, SnapshotService $snapshots)
    {
        $this->ownNovel($request, $novel);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        $snapshot = $snapshots->capture($novel, $request->user(), $data['name']);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}/snapshots")->with('status', 'Versión guardada.');
        }

        return response()->json(['snapshot' => $snapshot], 201);
    }

    public function show(Request $request, Snapshot $snapshot)
    {
        $this->ownSnapshot($request, $snapshot);

        return response()->json([
            'snapshot' => $snapshot->load('items'),
        ]);
    }

    public function rename(Request $request, Snapshot $snapshot)
    {
        $this->ownSnapshot($request, $snapshot);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        $snapshot->name = $data['name'];
        $snapshot->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$snapshot->novel_id}/snapshots")->with('status', 'Versión renombrada.');
        }

        return response()->json(['snapshot' => $snapshot->refresh()]);
    }

    public function compare(Request $request, Snapshot $snapshot, SnapshotService $snapshots)
    {
        $this->ownSnapshot($request, $snapshot);
        $diff = $snapshots->diff($snapshot);

        if ($request->expectsJson()) {
            return response()->json($diff);
        }

        $labels = [
            'novel' => 'Novela',
            'part' => 'Parte',
            'chapter' => 'Capítulo',
            'chapter_alternative' => 'Alternativa',
            'scene' => 'Escena',
            'card' => 'Ficha',
            'style' => 'Estilo',
            'footnote' => 'Nota',
        ];

        $changes = array_map(function (array $change) use ($labels): array {
            $change['resource_label'] = $labels[$change['resource_type']] ?? $change['resource_type'];
            $change['title'] = $this->changeTitle($change);
            $change['before'] = $this->excerpt($change['snapshot']);
            $change['after'] = $this->excerpt($change['current']);

            return $change;
        }, $diff['changes']);

        return view('workspace.snapshot-compare', [
            'snapshot' => $snapshot,
            'novel' => $snapshot->novel,
            'changes' => $changes,
            'counts' => array_count_values(array_column($changes, 'status')),
        ]);
    }

    private function changeTitle(array $change): string
    {
        foreach ([$change['current'], $change['snapshot']] as $payload) {
            if (is_array($payload)) {
                $title = $payload['title'] ?? $payload['name'] ?? $payload['label'] ?? null;

                if (is_string($title) && trim($title) !== '') {
                    return trim($title);
                }
            }
        }

        return $change['resource_label'].' #'.$change['resource_id'];
    }

    private function excerpt(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '—';
        }

        $blocks = $payload['content']['blocks'] ?? null;

        if (is_array($blocks)) {
            $text = [];

            foreach ($blocks as $block) {
                if (is_array($block) && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                    $text[] = trim($block['text']);
                }
            }

            if ($text !== []) {
                return mb_substr(implode(' ', $text), 0, 600, 'UTF-8');
            }
        }

        foreach (['summary', 'caption', 'description'] as $field) {
            if (is_string($payload[$field] ?? null) && trim($payload[$field]) !== '') {
                return mb_substr(trim($payload[$field]), 0, 600, 'UTF-8');
            }
        }

        return '—';
    }

    public function restorePreview(Request $request, Snapshot $snapshot, SnapshotService $snapshots)
    {
        $this->ownSnapshot($request, $snapshot);

        return response()->json([
            'changes' => $snapshots->diff($snapshot)['changes'],
            'revisions' => $snapshots->revisionMap($snapshot),
            'lease_required' => $snapshot->novel->chapters()->pluck('id')->all(),
        ]);
    }

    public function restoreFragment(Request $request, Snapshot $snapshot, SnapshotService $snapshots)
    {
        $this->ownSnapshot($request, $snapshot);
        $data = $request->validate([
            'resource_type' => ['required', 'string', 'max:40'],
            'resource_id' => ['required', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'block_id' => ['nullable', 'string', 'max:120'],
            'confirm' => ['accepted'],
        ]);

        $this->requireFragmentLease($request, $data['resource_type'], (int) $data['resource_id']);

        $result = $snapshots->restoreFragment(
            $snapshot,
            $request->user(),
            $data['resource_type'],
            (int) $data['resource_id'],
            (int) $data['expected_revision'],
            $data['block_id'] ?? null,
        );

        if ($result['stale']) {
            if ($request->query('format') === 'html') {
                return redirect("/workspace/snapshots/{$snapshot->id}/compare")
                    ->withErrors(['restore' => 'La versión cambió; revisa la comparación de nuevo.']);
            }

            return response()->json([
                'error' => 'stale_revision',
                'current_revision' => $result['current_revision'],
            ], 409);
        }

        if ($request->query('format') === 'html') {
            return redirect("/workspace/snapshots/{$snapshot->id}/compare")
                ->with('status', 'Fragmento restaurado desde la versión.');
        }

        return response()->json($result);
    }

    public function restore(Request $request, Snapshot $snapshot, SnapshotService $snapshots)
    {
        $this->ownSnapshot($request, $snapshot);
        $data = $request->validate([
            'revisions' => ['required', 'array'],
            'confirm' => ['accepted'],
        ]);

        foreach ($snapshot->novel->chapters()->pluck('id') as $chapterId) {
            abort_unless(
                EditLease::where('resource_type', 'chapter')
                    ->where('resource_id', $chapterId)
                    ->where('user_id', $request->user()->id)
                    ->where('expires_at', '>', now())
                    ->exists(),
                403,
                'lease_required',
            );
        }

        $result = $snapshots->restoreAll($snapshot, $request->user(), $data['revisions']);

        if ($result['stale']) {
            return response()->json([
                'error' => 'stale_revision',
                'resource_type' => $result['resource_type'],
                'resource_id' => $result['resource_id'],
                'current_revision' => $result['current_revision'],
            ], 409);
        }

        return response()->json($result);
    }

    private function requireFragmentLease(Request $request, string $type, int $id): void
    {
        $chapterId = match ($type) {
            'chapter' => Chapter::whereKey($id)->value('id'),
            'chapter_alternative' => ChapterAlternative::whereKey($id)->value('chapter_id'),
            'scene' => Chapter::whereKey(
                ChapterAlternative::whereKey(Scene::whereKey($id)->value('chapter_alternative_id'))->value('chapter_id'),
            )->value('id'),
            default => null,
        };

        if ($chapterId === null) {
            return;
        }

        abort_if(
            EditLease::where('resource_type', 'chapter')
                ->where('resource_id', $chapterId)
                ->where('user_id', '!=', $request->user()->id)
                ->where('expires_at', '>', now())
                ->exists(),
            403,
            'lease_required',
        );
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }

    private function ownSnapshot(Request $request, Snapshot $snapshot): void
    {
        $this->ownNovel($request, Novel::findOrFail($snapshot->novel_id));
    }
}
