<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Device;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\Universe;
use App\Models\User;
use App\Services\ContentAssets;
use App\Services\ContentDocument;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncOperationController extends Controller
{
    public function store(Request $request, ContentDocument $documents, ContentAssets $assets)
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'element_type' => ['required', 'in:scene,chapter,novel,card'],
            'element_id' => ['required', 'integer'],
            'action' => ['required', 'in:update_content,update_metadata,reorder,trash'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'payload' => ['present', 'array'],
        ]);

        $user = $request->user();
        $device = Device::whereKey($data['device_id'])->where('user_id', $user->id)->first();
        abort_unless($device, 404);

        if ($device->isRevoked()) {
            return response()->json(['status' => 'device_revoked'], 403);
        }

        if (! $device->authorized_at) {
            return response()->json(['status' => 'device_not_authorized'], 403);
        }

        $hash = $this->hash($data);

        if ($existing = SyncOperation::where('user_id', $user->id)
            ->where('device_id', $device->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first()) {
            return $this->replay($existing, $hash, $user);
        }

        try {
            return DB::transaction(function () use ($user, $device, $data, $documents, $assets) {
                $operation = SyncOperation::create([
                    'user_id' => $user->id,
                    'device_id' => $device->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $this->hash($data),
                ]);

                $result = $this->apply($user, $device, $data, $documents, $assets);

                $operation->forceFill(['result' => $result])->save();

                return response()->json($result['body'], $result['http_status']);
            });
        } catch (QueryException $exception) {
            $existing = SyncOperation::where('user_id', $user->id)
                ->where('device_id', $device->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->replay($existing, $hash, $user);
            }

            throw $exception;
        }
    }

    public function resolve(Request $request, SyncConflict $conflict, ContentDocument $documents, ContentAssets $assets)
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:server,client,merged'],
            'base_revision' => ['required', 'integer', 'min:0'],
            'content' => ['nullable', 'array'],
        ]);

        $scene = $conflict->scene_id ? Scene::find($conflict->scene_id) : null;
        $novel = $scene ? $this->novelForScene($scene) : null;

        abort_unless($scene && $novel && $novel->owner_id === $request->user()->id, 404);

        if (! in_array($conflict->status, ['pending', 'trashed_pending'], true)) {
            return response()->json(['status' => 'conflict_closed'], 409);
        }

        $this->assertSceneLease($scene, $request->user());

        if ($data['resolution'] === 'server') {
            return DB::transaction(function () use ($scene, $conflict, $data) {
                $lockedScene = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();
                $lockedConflict = SyncConflict::whereKey($conflict->id)->lockForUpdate()->firstOrFail();

                if (! in_array($lockedConflict->status, ['pending', 'trashed_pending'], true)) {
                    return response()->json(['status' => 'conflict_closed'], 409);
                }

                if ((int) $lockedScene->revision !== (int) $data['base_revision']) {
                    return response()->json([
                        'status' => 'stale_revision',
                        'server_revision' => $lockedScene->revision,
                    ], 409);
                }

                $lockedConflict->forceFill(['status' => 'resolved_server'])->save();

                return response()->json([
                    'conflict' => $lockedConflict->refresh(),
                    'resource' => ['type' => 'scene', 'id' => $lockedScene->id, 'revision' => $lockedScene->revision],
                ]);
            });
        }

        $content = $data['resolution'] === 'merged'
            ? ($data['content'] ?? [])
            : ($conflict->client_content ?? []);

        $content = $documents->validate($content);
        $assets->assertOwnedBy($content, $request->user()->id);

        if ($missing = $this->missingReferences($content, $novel)) {
            return response()->json([
                'status' => 'missing_resource',
                'missing_resources' => $missing,
            ], 422);
        }

        return DB::transaction(function () use ($scene, $content, $conflict, $data) {
            $lockedScene = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();
            $lockedConflict = SyncConflict::whereKey($conflict->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedConflict->status, ['pending', 'trashed_pending'], true)) {
                return response()->json(['status' => 'conflict_closed'], 409);
            }

            if ((int) $lockedScene->revision !== (int) $data['base_revision']) {
                return response()->json([
                    'status' => 'stale_revision',
                    'server_revision' => $lockedScene->revision,
                ], 409);
            }

            $lockedScene->forceFill([
                'content' => $content,
                'revision' => $lockedScene->revision + 1,
            ])->save();

            DB::table('sync_trash')
                ->where('resource_type', 'scene')
                ->where('resource_id', $lockedScene->id)
                ->delete();

            $lockedConflict->forceFill([
                'status' => $data['resolution'] === 'merged' ? 'resolved_merged' : 'resolved_client',
            ])->save();

            return response()->json([
                'conflict' => $lockedConflict->refresh(),
                'resource' => ['type' => 'scene', 'id' => $lockedScene->id, 'revision' => $lockedScene->revision],
            ]);
        });
    }

    private function apply(User $user, Device $device, array $data, ContentDocument $documents, ContentAssets $assets): array
    {
        return match ($data['element_type']) {
            'scene' => $this->applyScene($user, $device, $data, $documents, $assets),
            'novel' => $this->applyNovel($user, $device, $data),
            'chapter' => $this->applyChapter($user, $device, $data),
            'card' => $this->applyCard($user, $device, $data),
        };
    }

    private function applyScene(User $user, Device $device, array $data, ContentDocument $documents, ContentAssets $assets): array
    {
        $scene = Scene::find($data['element_id']);

        if (! $scene) {
            return $this->missing([['type' => 'scene', 'id' => $data['element_id']]]);
        }

        $novel = $this->novelForScene($scene);

        if (! $novel || $novel->owner_id !== $user->id) {
            abort(404);
        }

        if (! $this->deviceOwnsProject($device, $novel->id)) {
            return $this->projectNotAuthorized();
        }

        $this->assertSceneLease($scene, $user);

        $payload = $data['payload'];

        if ($this->isTrashed('scene', $scene->id)) {
            if ($data['action'] === 'trash') {
                return $this->applied(['scene_id' => $scene->id, 'already_trashed' => true]);
            }

            $content = $payload['content'] ?? $payload;

            $conflict = SyncConflict::create([
                'scene_id' => $scene->id,
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'server_revision' => $scene->revision,
                'client_revision' => $data['base_revision'],
                'server_content' => $scene->content,
                'client_content' => $content,
                'status' => 'trashed_pending',
            ]);

            return [
                'http_status' => 409,
                'body' => [
                    'status' => 'conflict',
                    'reason' => 'resource_trashed',
                    'resurrected' => false,
                    'conflict' => $conflict->toArray(),
                ],
            ];
        }

        if ($data['action'] === 'trash') {
            return DB::transaction(function () use ($scene, $data) {
                $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->revision !== (int) $data['base_revision']) {
                    return [
                        'http_status' => 409,
                        'body' => [
                            'status' => 'conflict',
                            'reason' => 'revision_conflict',
                            'current_revision' => (int) $locked->revision,
                        ],
                    ];
                }

                $this->trash('scene', $locked->id);

                return $this->applied(['scene_id' => $locked->id, 'trashed' => true]);
            });
        }

        if ($data['action'] === 'update_content') {
            $content = $documents->validate($payload['content'] ?? $payload);
            $assets->assertOwnedBy($content, $user->id);

            if ($missing = $this->missingReferences($content, $novel)) {
                return $this->missing($missing);
            }

            return DB::transaction(function () use ($scene, $data, $content) {
                $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->revision !== (int) $data['base_revision']) {
                    if ($locked->content == $content) {
                        return $this->applied(['scene_id' => $locked->id, 'revision' => $locked->revision]);
                    }

                    return $this->sceneConflict($locked, $data['base_revision'], $content, $locked->content);
                }

                $locked->forceFill([
                    'content' => $content,
                    'revision' => $locked->revision + 1,
                ])->save();

                return $this->applied(['scene_id' => $locked->id, 'revision' => $locked->revision]);
            });
        }

        $attributes = array_intersect_key($payload, array_flip(['title', 'summary', 'narrative_moment', 'plan']));

        if ($attributes === []) {
            throw ValidationException::withMessages(['payload' => 'Cambio no válido.']);
        }

        return DB::transaction(function () use ($scene, $data, $attributes) {
            $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== (int) $data['base_revision']) {
                return $this->sceneConflict(
                    $locked,
                    $data['base_revision'],
                    $attributes,
                    $locked->only(array_keys($attributes)),
                );
            }

            $locked->forceFill($attributes + ['revision' => $locked->revision + 1])->save();

            return $this->applied(['scene_id' => $locked->id, 'revision' => $locked->revision]);
        });
    }

    private function applyNovel(User $user, Device $device, array $data): array
    {
        $novel = Novel::find($data['element_id']);

        if (! $novel) {
            return $this->missing([['type' => 'novel', 'id' => $data['element_id']]]);
        }

        if ($novel->owner_id !== $user->id) {
            abort(404);
        }

        if (! $this->deviceOwnsProject($device, $novel->id)) {
            return $this->projectNotAuthorized();
        }

        if ($data['action'] !== 'reorder') {
            throw ValidationException::withMessages(['action' => 'Acción no válida.']);
        }

        $order = $data['payload']['order'] ?? null;

        if (! is_array($order) || $order === [] || collect($order)->contains(fn ($id) => ! is_int($id) && ! ctype_digit((string) $id))) {
            throw ValidationException::withMessages(['payload.order' => 'Orden no válido.']);
        }

        $order = array_map('intval', $order);
        $ids = $novel->chapters()->pluck('id')->all();

        if (count($ids) !== count($order)
            || array_diff($ids, $order) !== []
            || array_diff($order, $ids) !== []) {
            throw ValidationException::withMessages(['payload.order' => 'El orden debe contener todos los capítulos una vez.']);
        }

        $serverOrder = $novel->chapters()->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return DB::transaction(function () use ($novel, $data, $order, $serverOrder) {
            $locked = Novel::whereKey($novel->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== (int) $data['base_revision']) {
                $conflict = SyncConflict::create([
                    'scene_id' => null,
                    'resource_type' => 'novel',
                    'resource_id' => $locked->id,
                    'server_revision' => $locked->revision,
                    'client_revision' => $data['base_revision'],
                    'server_content' => ['order' => $serverOrder],
                    'client_content' => ['order' => $order],
                    'status' => 'pending',
                ]);

                return [
                    'http_status' => 409,
                    'body' => [
                        'status' => 'conflict',
                        'reason' => 'structure_conflict',
                        'conflict' => $conflict->toArray(),
                    ],
                ];
            }

            foreach ($order as $position => $id) {
                Chapter::whereKey($id)->update(['position' => $position]);
            }

            $locked->forceFill(['revision' => $locked->revision + 1])->save();

            return $this->applied(['novel_id' => $locked->id, 'revision' => $locked->revision]);
        });
    }

    private function applyChapter(User $user, Device $device, array $data): array
    {
        $chapter = Chapter::find($data['element_id']);

        if (! $chapter) {
            return $this->missing([['type' => 'chapter', 'id' => $data['element_id']]]);
        }

        $novel = Novel::find($chapter->novel_id);

        if (! $novel || $novel->owner_id !== $user->id) {
            abort(404);
        }

        if (! $this->deviceOwnsProject($device, $novel->id)) {
            return $this->projectNotAuthorized();
        }

        $this->assertLease('chapter', $chapter->id, $user);

        if ($this->isTrashed('chapter', $chapter->id)) {
            if ($data['action'] === 'trash') {
                return $this->applied(['chapter_id' => $chapter->id, 'already_trashed' => true]);
            }

            $conflict = SyncConflict::create([
                'scene_id' => null,
                'resource_type' => 'chapter',
                'resource_id' => $chapter->id,
                'server_revision' => $chapter->revision,
                'client_revision' => $data['base_revision'],
                'server_content' => $chapter->only(['title', 'plan']),
                'client_content' => $data['payload'],
                'status' => 'trashed_pending',
            ]);

            return [
                'http_status' => 409,
                'body' => [
                    'status' => 'conflict',
                    'reason' => 'resource_trashed',
                    'resurrected' => false,
                    'conflict' => $conflict->toArray(),
                ],
            ];
        }

        if ($data['action'] === 'trash') {
            return DB::transaction(function () use ($chapter, $data) {
                $locked = Chapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->revision !== (int) $data['base_revision']) {
                    return [
                        'http_status' => 409,
                        'body' => [
                            'status' => 'conflict',
                            'reason' => 'revision_conflict',
                            'current_revision' => (int) $locked->revision,
                        ],
                    ];
                }

                $this->trash('chapter', $locked->id);

                return $this->applied(['chapter_id' => $locked->id, 'trashed' => true]);
            });
        }

        $attributes = array_intersect_key($data['payload'], array_flip(['title', 'plan']));

        if ($attributes === []) {
            throw ValidationException::withMessages(['payload' => 'Cambio no válido.']);
        }

        return DB::transaction(function () use ($chapter, $data, $attributes) {
            $locked = Chapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== (int) $data['base_revision']) {
                $conflict = SyncConflict::create([
                    'scene_id' => null,
                    'resource_type' => 'chapter',
                    'resource_id' => $locked->id,
                    'server_revision' => $locked->revision,
                    'client_revision' => $data['base_revision'],
                    'server_content' => $locked->only(array_keys($attributes)),
                    'client_content' => $attributes,
                    'status' => 'pending',
                ]);

                return [
                    'http_status' => 409,
                    'body' => ['status' => 'conflict', 'conflict' => $conflict->toArray()],
                ];
            }

            $locked->forceFill($attributes + ['revision' => $locked->revision + 1])->save();

            return $this->applied(['chapter_id' => $locked->id, 'revision' => $locked->revision]);
        });
    }

    private function applyCard(User $user, Device $device, array $data): array
    {
        $card = Card::find($data['element_id']);

        if (! $card) {
            return $this->missing([['type' => 'card', 'id' => $data['element_id']]]);
        }

        $universe = Universe::find($card->universe_id);

        if (! $universe || $universe->owner_id !== $user->id) {
            abort(404);
        }

        $authorized = Novel::where('universe_id', $universe->id)
            ->whereIn('id', $device->projects()->pluck('novel_id'))
            ->exists();

        if (! $authorized) {
            return $this->projectNotAuthorized();
        }

        if ($this->isTrashed('card', $card->id)) {
            if ($data['action'] === 'trash') {
                return $this->applied(['card_id' => $card->id, 'already_trashed' => true]);
            }

            $conflict = SyncConflict::create([
                'scene_id' => null,
                'resource_type' => 'card',
                'resource_id' => $card->id,
                'server_revision' => $card->revision,
                'client_revision' => $data['base_revision'],
                'server_content' => $card->only(['name', 'template', 'fields', 'text']),
                'client_content' => $data['payload'],
                'status' => 'trashed_pending',
            ]);

            return [
                'http_status' => 409,
                'body' => [
                    'status' => 'conflict',
                    'reason' => 'resource_trashed',
                    'resurrected' => false,
                    'conflict' => $conflict->toArray(),
                ],
            ];
        }

        if ($data['action'] === 'trash') {
            return DB::transaction(function () use ($card, $data) {
                $locked = Card::whereKey($card->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->revision !== (int) $data['base_revision']) {
                    return [
                        'http_status' => 409,
                        'body' => [
                            'status' => 'conflict',
                            'reason' => 'revision_conflict',
                            'current_revision' => (int) $locked->revision,
                        ],
                    ];
                }

                $this->trash('card', $locked->id);

                return $this->applied(['card_id' => $locked->id, 'trashed' => true]);
            });
        }

        $attributes = array_intersect_key($data['payload'], array_flip(['name', 'template', 'fields', 'text']));

        if ($attributes === []) {
            throw ValidationException::withMessages(['payload' => 'Cambio no válido.']);
        }

        return DB::transaction(function () use ($card, $data, $attributes) {
            $locked = Card::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== (int) $data['base_revision']) {
                $conflict = SyncConflict::create([
                    'scene_id' => null,
                    'resource_type' => 'card',
                    'resource_id' => $locked->id,
                    'server_revision' => $locked->revision,
                    'client_revision' => $data['base_revision'],
                    'server_content' => $locked->only(array_keys($attributes)),
                    'client_content' => $attributes,
                    'status' => 'pending',
                ]);

                return [
                    'http_status' => 409,
                    'body' => ['status' => 'conflict', 'conflict' => $conflict->toArray()],
                ];
            }

            $locked->forceFill($attributes + ['revision' => $locked->revision + 1])->save();

            return $this->applied(['card_id' => $locked->id, 'revision' => $locked->revision]);
        });
    }

    private function replay(SyncOperation $operation, string $hash, User $user)
    {
        if ($operation->user_id !== $user->id || ! hash_equals($operation->payload_hash, $hash)) {
            return response()->json(['status' => 'idempotency_key_reused'], 409);
        }

        if (! is_array($operation->result)) {
            return response()->json(['status' => 'operation_in_progress'], 409);
        }

        return response()->json(
            $operation->result['body'],
            $operation->result['http_status'] ?? 200,
        );
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode([
            'element_type' => $data['element_type'],
            'element_id' => $data['element_id'],
            'action' => $data['action'],
            'base_revision' => $data['base_revision'],
            'payload' => $data['payload'],
        ]));
    }

    private function sceneConflict(Scene $scene, int $clientRevision, array $clientContent, ?array $serverContent): array
    {
        $conflict = SyncConflict::create([
            'scene_id' => $scene->id,
            'resource_type' => 'scene',
            'resource_id' => $scene->id,
            'server_revision' => $scene->revision,
            'client_revision' => $clientRevision,
            'server_content' => $serverContent,
            'client_content' => $clientContent,
            'status' => 'pending',
        ]);

        return [
            'http_status' => 409,
            'body' => ['status' => 'conflict', 'conflict' => $conflict->toArray()],
        ];
    }

    private function missing(array $resources): array
    {
        return [
            'http_status' => 422,
            'body' => ['status' => 'missing_resource', 'missing_resources' => $resources],
        ];
    }

    private function projectNotAuthorized(): array
    {
        return [
            'http_status' => 403,
            'body' => ['status' => 'project_not_authorized'],
        ];
    }

    private function applied(array $data): array
    {
        return ['http_status' => 200, 'body' => ['status' => 'applied'] + $data];
    }

    private function deviceOwnsProject(Device $device, int $novelId): bool
    {
        return $device->projects()->where('novel_id', $novelId)->exists();
    }

    private function isTrashed(string $type, int $id): bool
    {
        return DB::table('sync_trash')
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->exists();
    }

    private function trash(string $type, int $id): void
    {
        DB::table('sync_trash')->updateOrInsert(
            ['resource_type' => $type, 'resource_id' => $id],
            ['trashed_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function assertSceneLease(Scene $scene, User $user): void
    {
        $chapterId = Chapter::whereKey(
            ChapterAlternative::whereKey($scene->chapter_alternative_id)->value('chapter_id'),
        )->value('id');

        if ($chapterId !== null) {
            $this->assertLease('chapter', (int) $chapterId, $user);
        }
    }

    private function assertLease(string $type, int $id, User $user): void
    {
        $lease = DB::table('edit_leases')
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->where('expires_at', '>', now())
            ->first();

        if ($lease && (int) $lease->user_id !== $user->id) {
            abort(423, 'Reserva activa de otro usuario.');
        }
    }

    private function novelForScene(Scene $scene): ?Novel
    {
        $alternative = ChapterAlternative::find($scene->chapter_alternative_id);
        $chapter = $alternative ? Chapter::find($alternative->chapter_id) : null;

        return $chapter ? Novel::find($chapter->novel_id) : null;
    }

    private function missingReferences(array $content, Novel $novel): array
    {
        $ids = [];

        foreach ($content['blocks'] ?? [] as $block) {
            foreach (($block['references'] ?? []) as $reference) {
                $id = is_int($reference)
                    ? $reference
                    : (is_array($reference) ? ($reference['card_id'] ?? $reference['id'] ?? null) : null);

                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $existing = Card::where('universe_id', $novel->universe_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_map(
            fn (int $id) => ['type' => 'card', 'id' => $id],
            array_values(array_diff($ids, $existing)),
        );
    }
}
