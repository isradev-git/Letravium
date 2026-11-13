<?php

namespace App\Services;

use App\Exceptions\RevisionConflict;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Scene;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistoryService
{
    public const AUTOMATIC_INTERVAL_MINUTES = 5;

    public const AUTOMATIC_RETENTION_DAYS = 90;

    public function saveScene(Scene $scene, array $content, int $baseRevision, int $authorId, string $reason = 'save'): Scene
    {
        return DB::transaction(function () use ($scene, $content, $baseRevision, $authorId, $reason) {
            $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== $baseRevision) {
                throw new RevisionConflict((int) $locked->revision, (int) $locked->id);
            }

            $this->record((int) $locked->id, (int) $locked->revision, $locked->content ?? [], $authorId, $reason);

            $locked->forceFill([
                'content' => $content,
                'revision' => (int) $locked->revision + 1,
            ])->save();

            return $locked;
        });
    }

    public function saveChapter(Chapter $chapter, array $scenes, int $baseRevision, int $authorId, string $reason = 'save'): Chapter
    {
        return DB::transaction(function () use ($chapter, $scenes, $baseRevision, $authorId, $reason) {
            $locked = Chapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== $baseRevision) {
                throw new RevisionConflict((int) $locked->revision);
            }

            foreach ($scenes as $item) {
                $scene = Scene::whereKey($item['id'])->lockForUpdate()->firstOrFail();
                $belongs = ChapterAlternative::whereKey($scene->chapter_alternative_id)
                    ->where('chapter_id', $locked->id)->exists();

                if (! $belongs) {
                    throw ValidationException::withMessages(['scenes' => 'La escena no pertenece al capítulo.']);
                }

                if ((int) $scene->revision !== (int) $item['base_revision']) {
                    throw new RevisionConflict((int) $scene->revision, (int) $scene->id);
                }
            }

            foreach ($scenes as $item) {
                $scene = Scene::findOrFail($item['id']);
                $this->record((int) $scene->id, (int) $scene->revision, $scene->content ?? [], $authorId, $reason);
                $scene->forceFill([
                    'content' => $item['content'],
                    'revision' => (int) $scene->revision + 1,
                ])->save();
            }

            $locked->forceFill(['revision' => (int) $locked->revision + 1])->save();

            return $locked;
        });
    }

    public function splitScene(Scene $scene, int $blockIndex, int $baseRevision, int $authorId): array
    {
        return DB::transaction(function () use ($scene, $blockIndex, $baseRevision, $authorId) {
            $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== $baseRevision) {
                throw new RevisionConflict((int) $locked->revision, (int) $locked->id);
            }

            $blocks = $locked->content['blocks'] ?? [];

            if ($blockIndex < 1 || $blockIndex >= count($blocks)) {
                throw ValidationException::withMessages([
                    'block_index' => 'El índice debe dividir el contenido en dos partes con texto.',
                ]);
            }

            $this->record((int) $locked->id, (int) $locked->revision, $locked->content ?? [], $authorId, 'split');

            $first = array_slice($blocks, 0, $blockIndex);
            $second = array_slice($blocks, $blockIndex);

            Scene::where('chapter_alternative_id', $locked->chapter_alternative_id)
                ->where('position', '>', $locked->position)
                ->increment('position');

            $created = Scene::create([
                'chapter_alternative_id' => $locked->chapter_alternative_id,
                'position' => $locked->position + 1,
                'content' => [
                    'schema_version' => $locked->content['schema_version'] ?? 1,
                    'blocks' => $second,
                ],
            ]);

            $locked->forceFill([
                'content' => [
                    'schema_version' => $locked->content['schema_version'] ?? 1,
                    'blocks' => $first,
                ],
                'revision' => (int) $locked->revision + 1,
            ])->save();

            $this->record((int) $created->id, 1, $created->content ?? [], $authorId, 'split');

            return ['scene' => $locked->refresh(), 'created_scene' => $created->refresh()];
        });
    }

    public function mergeScene(Scene $scene, int $baseRevision, int $authorId): Scene
    {
        return DB::transaction(function () use ($scene, $baseRevision, $authorId) {
            $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->revision !== $baseRevision) {
                throw new RevisionConflict((int) $locked->revision, (int) $locked->id);
            }

            $next = Scene::where('chapter_alternative_id', $locked->chapter_alternative_id)
                ->where('position', $locked->position + 1)
                ->lockForUpdate()
                ->first();

            if (! $next) {
                throw ValidationException::withMessages(['scene' => 'No hay una escena siguiente que unir.']);
            }

            $this->record((int) $locked->id, (int) $locked->revision, $locked->content ?? [], $authorId, 'merge');
            $this->record((int) $next->id, (int) $next->revision, $next->content ?? [], $authorId, 'merge');

            foreach (DB::table('plot_scene')->where('scene_id', $next->id)->pluck('plot_id') as $plotId) {
                DB::table('plot_scene')->insertOrIgnore(['plot_id' => $plotId, 'scene_id' => $locked->id]);
            }
            DB::table('plot_scene')->where('scene_id', $next->id)->delete();

            foreach (DB::table('text_references')->where('scene_id', $next->id)->get() as $reference) {
                DB::table('text_references')->insertOrIgnore([
                    'scene_id' => $locked->id,
                    'card_id' => $reference->card_id,
                    'created_by' => $reference->created_by,
                    'created_at' => $reference->created_at,
                    'updated_at' => $reference->updated_at,
                ]);
            }
            DB::table('text_references')->where('scene_id', $next->id)->delete();
            DB::table('footnotes')->where('scene_id', $next->id)->update(['scene_id' => $locked->id]);

            $merged = array_merge($locked->content['blocks'] ?? [], $next->content['blocks'] ?? []);

            $locked->forceFill([
                'content' => [
                    'schema_version' => $locked->content['schema_version'] ?? 1,
                    'blocks' => $merged,
                ],
                'revision' => (int) $locked->revision + 1,
            ])->save();

            $next->delete();

            return $locked->refresh();
        });
    }

    public function record(int $sceneId, int $revision, array $content, int $authorId, string $reason, ?Carbon $at = null): void
    {
        DB::table('content_revisions')->insertOrIgnore([
            'scene_id' => $sceneId,
            'revision' => $revision,
            'content' => json_encode($content),
            'author_id' => $authorId,
            'reason' => $reason,
            'created_at' => $at ?? now(),
        ]);
    }

    public function automaticPointDue(?string $lastAutomaticAt, Carbon $now): bool
    {
        return $lastAutomaticAt === null
            || Carbon::parse($lastAutomaticAt)->diffInMinutes($now) >= self::AUTOMATIC_INTERVAL_MINUTES;
    }

    public function recordAutomatic(Scene $scene, int $authorId, ?Carbon $now = null): bool
    {
        $now ??= now();

        $last = DB::table('content_revisions')
            ->where('scene_id', $scene->id)
            ->where('reason', 'automatic')
            ->max('created_at');

        if (! $this->automaticPointDue($last, $now)) {
            return false;
        }

        $this->record((int) $scene->id, (int) $scene->revision, $scene->content ?? [], $authorId, 'automatic', $now);

        return true;
    }

    public function pruneAutomatic(int $days = self::AUTOMATIC_RETENTION_DAYS): int
    {
        return DB::table('content_revisions')
            ->where('reason', 'automatic')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
