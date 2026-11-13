<?php

namespace App\Http\Controllers;

use App\Models\BetaReader;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Snapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BetaReaderController extends Controller
{
    public function index(Request $request, Snapshot $snapshot)
    {
        $this->ownSnapshot($request, $snapshot);
        $readers = $snapshot->betaReaders()->with('user:id,name,email')->latest('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['beta_readers' => $readers]);
        }

        return view('workspace.beta-readers', [
            'snapshot' => $snapshot,
            'novel' => $snapshot->novel,
            'readers' => $readers,
            'chapters' => $snapshot->novel->chapters()->get(['id', 'title', 'position']),
        ]);
    }

    public function store(Request $request, Snapshot $snapshot)
    {
        $this->ownSnapshot($request, $snapshot);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'email', 'max:200'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'can_comment' => ['boolean'],
            'all_chapters' => ['sometimes', 'boolean'],
            'scope' => ['nullable', 'array'],
            'scope.chapters' => ['nullable', 'array'],
            'scope.chapters.*' => ['integer'],
            'scope.cards' => ['nullable', 'array'],
            'scope.cards.*' => ['integer'],
        ]);

        abort_if(empty($data['user_id']) && empty($data['email']), 422, 'A user or email is required.');
        $scope = $this->scope($snapshot, $data);

        $reader = BetaReader::create([
            'snapshot_id' => $snapshot->id,
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null,
            'token' => Str::random(64),
            'expires_at' => $data['expires_at'] ?? now()->addDays(30),
            'can_comment' => $data['can_comment'] ?? true,
            'scope' => $scope,
        ]);

        if (! $request->expectsJson()) {
            return redirect("/workspace/snapshots/{$snapshot->id}/beta-readers")->with('status', 'Lector beta invitado.');
        }

        return response()->json([
            'beta_reader' => $reader->makeVisible('token'),
        ], 201);
    }

    public function update(Request $request, BetaReader $reader)
    {
        $snapshot = $reader->snapshot;
        $this->ownSnapshot($request, $snapshot);

        $data = $request->validate([
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'can_comment' => ['sometimes', 'boolean'],
            'all_chapters' => ['sometimes', 'boolean'],
            'scope' => ['nullable', 'array'],
            'scope.chapters' => ['nullable', 'array'],
            'scope.chapters.*' => ['integer'],
            'scope.cards' => ['nullable', 'array'],
            'scope.cards.*' => ['integer'],
        ]);

        $reader->fill(array_intersect_key($data, array_flip(['expires_at', 'can_comment'])));
        $reader->scope = $this->scope($snapshot, $data);
        $reader->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/snapshots/{$snapshot->id}/beta-readers")->with('status', 'Lector beta actualizado.');
        }

        return response()->json(['beta_reader' => $reader->makeVisible('token')]);
    }

    public function destroy(Request $request, BetaReader $reader)
    {
        $snapshot = $reader->snapshot;
        $this->ownSnapshot($request, $snapshot);

        $reader->delete();

        if (! $request->expectsJson()) {
            return redirect("/workspace/snapshots/{$snapshot->id}/beta-readers")->with('status', 'Acceso de lector beta revocado.');
        }

        return response()->json(['status' => 'deleted']);
    }

    public function show(Request $request, string $token)
    {
        $reader = BetaReader::where('token', $token)->firstOrFail();

        abort_unless($reader->isActive(), 403, 'Beta reader access expired.');

        $user = $request->user();

        if ($reader->user_id !== null) {
            abort_unless((int) $reader->user_id === (int) $user->id, 403);
        } else {
            abort_unless($reader->email === $user->email, 403);
        }

        $snapshot = $reader->snapshot;
        $content = $this->limit($snapshot->manifest, $reader->scope);

        return response()->json([
            'snapshot' => ['id' => $snapshot->id, 'name' => $snapshot->name],
            'content' => $content,
            'can_comment' => $reader->can_comment,
        ]);
    }

    private function scope(Snapshot $snapshot, array $data): ?array
    {
        if (array_key_exists('all_chapters', $data)) {
            if ((bool) $data['all_chapters']) {
                return null;
            }

            $ids = array_values(array_unique(array_map('intval', $data['scope']['chapters'] ?? [])));
            abort_if($ids === [], 422, 'Selecciona al menos un capítulo o todos.');

            $valid = Chapter::whereIn('id', $ids)
                ->where('novel_id', $snapshot->novel_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            abort_unless(count($valid) === count($ids), 422, 'La selección contiene capítulos ajenos.');

            return ['chapters' => $ids];
        }

        if (isset($data['scope']['chapters'])) {
            $ids = array_values(array_unique(array_map('intval', $data['scope']['chapters'])));
            $valid = Chapter::whereIn('id', $ids)
                ->where('novel_id', $snapshot->novel_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            abort_unless(count($valid) === count($ids), 422, 'La selección contiene capítulos ajenos.');

            return ['chapters' => $ids];
        }

        return $data['scope'] ?? null;
    }

    private function limit(array $manifest, ?array $scope): array
    {
        if ($scope === null) {
            return $manifest;
        }

        $hasChapters = array_key_exists('chapters', $scope);
        $hasCards = array_key_exists('cards', $scope);
        $allowedScenes = [];
        $allowedAssets = [];
        $allowedCardIds = [];
        $allowedPartIds = [];
        $allowedStateIds = [];

        if ($hasChapters) {
            $ids = array_values(array_unique(array_map('intval', (array) $scope['chapters'])));
            $manifest['chapters'] = array_values(array_filter(
                $manifest['chapters'] ?? [],
                fn ($chapter) => in_array((int) $chapter['id'], $ids, true),
            ));

            foreach ($manifest['chapters'] as $chapter) {
                if (! empty($chapter['part_id'])) {
                    $allowedPartIds[(int) $chapter['part_id']] = true;
                }

                foreach (($chapter['alternatives'] ?? []) as $alternative) {
                    if (! ($alternative['is_active'] ?? false)) {
                        continue;
                    }

                    if (! empty($alternative['work_state_id'])) {
                        $allowedStateIds[(int) $alternative['work_state_id']] = true;
                    }

                    foreach (($alternative['scenes'] ?? []) as $scene) {
                        $allowedScenes[(int) $scene['id']] = true;

                        foreach (['pov_card_id', 'place_card_id'] as $cardKey) {
                            if (! empty($scene[$cardKey])) {
                                $allowedCardIds[(int) $scene[$cardKey]] = true;
                            }
                        }

                        foreach (($scene['card_ids'] ?? []) as $cardId) {
                            $allowedCardIds[(int) $cardId] = true;
                        }

                        foreach (($scene['content']['blocks'] ?? []) as $block) {
                            $asset = $block['attrs']['asset_id'] ?? null;

                            if (is_int($asset) && $asset > 0) {
                                $allowedAssets[$asset] = true;
                            } elseif (is_string($asset) && ctype_digit($asset)) {
                                $allowedAssets[(int) $asset] = true;
                            }
                        }
                    }
                }
            }

            $inScope = fn (?string $targetType, mixed $targetId): bool => match ($targetType) {
                'chapter' => in_array((int) $targetId, $ids, true),
                'scene' => isset($allowedScenes[(int) $targetId]),
                default => false,
            };

            $sceneId = fn (mixed $target): int => preg_match('/scene:(\d+)/', (string) $target, $match) === 1
                ? (int) $match[1]
                : 0;

            if (isset($manifest['navigation']['toc'])) {
                $toc = [];

                foreach ($manifest['navigation']['toc'] as $entry) {
                    if (! in_array((int) preg_replace('/\D+/', '', (string) ($entry['target'] ?? '')), $ids, true)) {
                        continue;
                    }

                    if (isset($entry['children'])) {
                        $entry['children'] = array_values(array_filter(
                            $entry['children'],
                            fn (array $child) => isset($allowedScenes[$sceneId($child['target'] ?? '')]),
                        ));
                    }

                    $toc[] = $entry;
                }

                $manifest['navigation']['toc'] = $toc;
            }

            foreach (['figures', 'tables', 'index', 'glossary'] as $collection) {
                if (isset($manifest['navigation'][$collection])) {
                    $manifest['navigation'][$collection] = array_values(array_filter(
                        $manifest['navigation'][$collection],
                        fn (array $entry) => $inScope($entry['target_type'] ?? null, $entry['target_id'] ?? null),
                    ));
                }
            }

            if (isset($manifest['notes'])) {
                $manifest['notes'] = array_values(array_filter(
                    $manifest['notes'],
                    fn (array $note) => isset($allowedScenes[(int) ($note['scene_id'] ?? 0)]),
                ));
            }

            // Global fields (metadata, profile, styles, fonts) are not tied to a
            // chapter; chapter-bound sections and media are filtered above.
            $manifest['sections'] = [];

            if (isset($manifest['resources']['media'])) {
                $manifest['resources']['media'] = array_values(array_filter(
                    $manifest['resources']['media'],
                    fn (array $asset) => isset($allowedAssets[(int) ($asset['id'] ?? 0)]),
                ));
            }

            $manifest['parts'] = array_values(array_filter(
                $manifest['parts'] ?? [],
                fn (array $part) => isset($allowedPartIds[(int) ($part['id'] ?? 0)]),
            ));
            $manifest['work_states'] = array_values(array_filter(
                $manifest['work_states'] ?? [],
                fn (array $state) => isset($allowedStateIds[(int) ($state['id'] ?? 0)]),
            ));
        }

        if ($hasCards) {
            $ids = array_values(array_unique(array_map('intval', (array) $scope['cards'])));
            $manifest['cards'] = array_values(array_filter(
                $manifest['cards'] ?? [],
                fn ($card) => in_array((int) $card['id'], $ids, true),
            ));

            if (! $hasChapters) {
                $manifest['chapters'] = [];
                $manifest['parts'] = [];
                $manifest['work_states'] = [];
                $manifest['notes'] = [];
                $manifest['sections'] = [];

                if (isset($manifest['resources']['media'])) {
                    $manifest['resources']['media'] = [];
                }

                foreach (['toc', 'figures', 'tables', 'index', 'glossary'] as $collection) {
                    if (isset($manifest['navigation'][$collection])) {
                        $manifest['navigation'][$collection] = [];
                    }
                }
            }
        } elseif ($hasChapters) {
            $manifest['cards'] = array_values(array_filter(
                $manifest['cards'] ?? [],
                fn ($card) => isset($allowedCardIds[(int) ($card['id'] ?? 0)]),
            ));
        }

        return $manifest;
    }

    private function ownSnapshot(Request $request, Snapshot $snapshot): void
    {
        $novel = Novel::findOrFail($snapshot->novel_id);
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
