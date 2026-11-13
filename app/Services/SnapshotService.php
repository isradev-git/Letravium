<?php

namespace App\Services;

use App\Models\BookMetadata;
use App\Models\BookProfile;
use App\Models\BookSection;
use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Part;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Style;
use App\Models\User;
use App\Models\WorkState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SnapshotService
{
    public function __construct(private HistoryService $history, private BookNavigation $navigation) {}

    private const TYPES = [
        'novel' => [Novel::class, ['title', 'settings'], true],
        'part' => [Part::class, ['novel_id', 'title', 'position'], false],
        'work_state' => [WorkState::class, ['novel_id', 'name', 'position'], false],
        'chapter' => [Chapter::class, ['novel_id', 'part_id', 'title', 'position', 'plan'], true],
        'chapter_alternative' => [ChapterAlternative::class, ['chapter_id', 'work_state_id', 'alias', 'is_active', 'plan'], true],
        'scene' => [Scene::class, ['chapter_alternative_id', 'title', 'position', 'summary', 'pov_card_id', 'place_card_id', 'narrative_moment', 'plan', 'content'], true],
        'style' => [Style::class, ['novel_id', 'name', 'kind', 'definition'], false],
        'font' => [Font::class, ['id', 'owner_id', 'family', 'variant', 'path', 'bytes', 'license', 'metadata'], false],
    ];

    private const RESTORE_TYPES = ['novel', 'part', 'work_state', 'chapter', 'chapter_alternative', 'scene', 'style'];

    private const FRAGMENT_TYPES = ['part', 'work_state', 'chapter', 'chapter_alternative', 'scene', 'style'];

    public function capture(Novel $novel, User $user, ?string $name = null): Snapshot
    {
        $state = $this->state($novel);

        return DB::transaction(function () use ($novel, $user, $name, $state) {
            $snapshot = Snapshot::create([
                'novel_id' => $novel->id,
                'name' => $name ?? 'Versión '.now()->format('Y-m-d H:i'),
                'manifest' => $state['manifest'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            foreach ($state['items'] as $item) {
                $snapshot->items()->create($item);
            }

            return $snapshot->load('items');
        });
    }

    public function state(Novel $novel): array
    {
        $novel->load(['parts', 'workStates', 'chapters.alternatives.scenes', 'styles']);

        $items = [$this->item('novel', $novel, $this->payload('novel', $novel))];

        foreach ($novel->parts as $part) {
            $items[] = $this->item('part', $part, $this->payload('part', $part));
        }

        foreach ($novel->workStates as $state) {
            $items[] = $this->item('work_state', $state, $this->payload('work_state', $state));
        }

        foreach ($novel->chapters as $chapter) {
            $items[] = $this->item('chapter', $chapter, $this->payload('chapter', $chapter));

            foreach ($chapter->alternatives as $alternative) {
                $items[] = $this->item('chapter_alternative', $alternative, $this->payload('chapter_alternative', $alternative));

                foreach ($alternative->scenes as $scene) {
                    $items[] = $this->item('scene', $scene, $this->payload('scene', $scene));
                }
            }
        }

        foreach ($novel->styles as $style) {
            $items[] = $this->item('style', $style, $this->payload('style', $style));
        }

        $fonts = $this->fontsFor($novel);

        foreach ($fonts as $font) {
            $items[] = $this->item('font', $font, $this->payload('font', $font));
        }

        $profile = BookProfile::where('novel_id', $novel->id)->latest('id')->first();
        $media = $this->mediaFor($novel, $profile);

        $metadata = BookMetadata::where('novel_id', $novel->id)->first();
        $sections = BookSection::where('novel_id', $novel->id)
            ->where('include_in_export', true)
            ->orderByRaw("case when area = 'front' then 0 else 1 end")
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (BookSection $section) => [
                'id' => $section->id,
                'area' => $section->area,
                'type' => $section->type,
                'title' => $section->title,
                'content' => $section->content,
            ])->all();
        $notes = Footnote::where('novel_id', $novel->id)
            ->orderBy('kind')
            ->orderBy('number')
            ->get()
            ->map(fn (Footnote $note) => [
                'id' => $note->id,
                'kind' => $note->kind,
                'number' => $note->number,
                'text' => $note->text,
                'content' => $note->content,
                'scene_id' => $note->scene_id,
            ])->all();

        $sceneIds = $novel->chapters
            ->flatMap(fn (Chapter $chapter) => $chapter->alternatives)
            ->flatMap(fn (ChapterAlternative $alternative) => $alternative->scenes)
            ->pluck('id')
            ->all();
        $cardIdsByScene = [];

        if ($sceneIds !== []) {
            foreach (DB::table('text_references')->whereIn('scene_id', $sceneIds)->get() as $reference) {
                $cardIdsByScene[(int) $reference->scene_id][] = (int) $reference->card_id;
            }
        }

        $manifest = [
            'format_version' => 3,
            'captured_at' => now()->toIso8601String(),
            'novel' => ['id' => $novel->id, 'title' => $novel->title, 'revision' => (int) $novel->revision],
            'parts' => $novel->parts->map(fn (Part $part) => [
                'id' => $part->id,
                'title' => $part->title,
                'position' => (int) $part->position,
            ])->all(),
            'work_states' => $novel->workStates->map(fn (WorkState $state) => [
                'id' => $state->id,
                'name' => $state->name,
                'position' => (int) $state->position,
            ])->all(),
            'chapters' => $novel->chapters->map(fn (Chapter $chapter) => [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'position' => (int) $chapter->position,
                'part_id' => $chapter->part_id,
                'revision' => (int) $chapter->revision,
                'alternatives' => $chapter->alternatives->map(fn (ChapterAlternative $alternative) => [
                    'id' => $alternative->id,
                    'alias' => $alternative->alias,
                    'is_active' => (bool) $alternative->is_active,
                    'work_state_id' => $alternative->work_state_id,
                    'revision' => (int) $alternative->revision,
                    'scenes' => $alternative->scenes->map(fn (Scene $scene) => [
                        'id' => $scene->id,
                        'title' => $scene->title,
                        'position' => (int) $scene->position,
                        'revision' => (int) $scene->revision,
                        'pov_card_id' => $scene->pov_card_id,
                        'place_card_id' => $scene->place_card_id,
                        'card_ids' => $cardIdsByScene[(int) $scene->id] ?? [],
                        'content' => $scene->content,
                    ])->all(),
                ])->all(),
            ])->all(),
            'styles' => $novel->styles->map(fn (Style $style) => [
                'id' => $style->id,
                'name' => $style->name,
                'kind' => $style->kind,
                'definition' => $style->definition,
            ])->all(),
            'fonts' => $fonts->map(fn (Font $font) => $this->payload('font', $font))->all(),
            'media' => $media->map(fn (MediaAsset $asset) => [
                'id' => $asset->id,
                'original_name' => $asset->original_name,
                'mime' => $asset->mime,
                'path' => $asset->path,
                'bytes' => $asset->bytes,
                'metadata' => $asset->metadata,
                'exists' => Storage::disk('local')->exists($asset->path),
            ])->all(),
            'cards' => $this->referencedCards($novel),
            'metadata' => $metadata?->only([
                'title', 'subtitle', 'author', 'language', 'description',
                'rights', 'edition', 'publisher', 'isbn',
            ]),
            'sections' => $sections,
            'notes' => $notes,
            'profile' => $profile?->toArray(),
            'navigation' => $this->navigation->build($novel),
        ];

        return ['manifest' => $manifest, 'items' => $items];
    }

    public function diff(Snapshot $snapshot): array
    {
        $snapshotItems = collect($snapshot->items)->keyBy(fn ($item) => $item->resource_type.':'.$item->resource_id);
        $currentItems = collect($this->state($snapshot->novel)['items'])
            ->keyBy(fn ($item) => $item['resource_type'].':'.$item['resource_id']);

        $changes = [];

        foreach ($snapshotItems as $key => $item) {
            $current = $currentItems->get($key);

            if ($current === null) {
                $changes[] = [
                    'resource_type' => $item->resource_type,
                    'resource_id' => (int) $item->resource_id,
                    'status' => 'removed',
                    'fields' => [],
                    'snapshot' => $item->payload,
                    'current' => null,
                ];

                continue;
            }

            $fields = $this->changedFields($item->payload, $current['payload']);

            if ($fields !== []) {
                $changes[] = [
                    'resource_type' => $item->resource_type,
                    'resource_id' => (int) $item->resource_id,
                    'status' => 'modified',
                    'fields' => $fields,
                    'snapshot' => $item->payload,
                    'current' => $current['payload'],
                    'snapshot_revision' => (int) $item->revision,
                    'current_revision' => $current['revision'],
                ];
            }
        }

        foreach ($currentItems as $key => $current) {
            if ($snapshotItems->has($key)) {
                continue;
            }

            $changes[] = [
                'resource_type' => $current['resource_type'],
                'resource_id' => (int) $current['resource_id'],
                'status' => 'added',
                'fields' => [],
                'snapshot' => null,
                'current' => $current['payload'],
                'current_revision' => $current['revision'],
            ];
        }

        return ['snapshot_id' => $snapshot->id, 'changes' => $changes];
    }

    public function revisionMap(Snapshot $snapshot): array
    {
        $map = [];

        foreach ($this->state($snapshot->novel)['items'] as $item) {
            if (in_array($item['resource_type'], self::RESTORE_TYPES, true)) {
                $map[$item['resource_type'].':'.$item['resource_id']] = $item['revision'];
            }
        }

        return $map;
    }

    public function restoreFragment(Snapshot $snapshot, User $user, string $type, int $id, int $expectedRevision, ?string $blockId = null): array
    {
        abort_unless(in_array($type, self::FRAGMENT_TYPES, true), 422, 'Unsupported resource type.');

        $item = $snapshot->items()
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->firstOrFail();

        return DB::transaction(function () use ($snapshot, $user, $type, $item, $expectedRevision, $blockId) {
            [$class, $fields, $revisioned] = self::TYPES[$type];

            $model = $class::findOrFail($item->resource_id);
            $this->assertBelongs($type, $model, $snapshot->novel_id);

            $currentRevision = $revisioned ? (int) $model->revision : 0;

            if ($currentRevision !== $expectedRevision) {
                return ['stale' => true, 'current_revision' => $currentRevision];
            }

            $this->recordHistory($snapshot->novel_id, $type, $model, $user, 'fragment_restore');

            $attributes = [];

            foreach ($fields as $field) {
                $attributes[$field] = $item->payload[$field] ?? null;
            }

            if ($type === 'scene' && $blockId !== null) {
                $blocks = $model->content['blocks'] ?? [];
                $source = $this->blockById($item->payload['content']['blocks'] ?? [], $blockId);

                abort_if($source === null, 404, 'Block not found in snapshot.');

                $index = $this->blockIndex($blocks, $blockId);

                if ($index === null) {
                    $blocks[] = $source;
                } else {
                    $blocks[$index] = $source;
                }

                $attributes['content'] = [
                    'schema_version' => $model->content['schema_version'] ?? 1,
                    'blocks' => $blocks,
                ];
            }

            if ($revisioned) {
                $attributes['revision'] = $currentRevision + 1;
            }

            $model->forceFill($attributes)->save();

            return [
                'stale' => false,
                'resource_type' => $type,
                'resource_id' => (int) $model->id,
                'revision' => $revisioned ? $currentRevision + 1 : 0,
            ];
        });
    }

    public function restoreAll(Snapshot $snapshot, User $user, array $revisions): array
    {
        return DB::transaction(function () use ($snapshot, $user, $revisions) {
            $novel = Novel::findOrFail($snapshot->novel_id);
            $current = $this->state($novel);

            foreach ($current['items'] as $item) {
                if (! in_array($item['resource_type'], self::RESTORE_TYPES, true)) {
                    continue;
                }

                $key = $item['resource_type'].':'.$item['resource_id'];

                if (! array_key_exists($key, $revisions) || (int) $revisions[$key] !== $item['revision']) {
                    return [
                        'stale' => true,
                        'resource_type' => $item['resource_type'],
                        'resource_id' => (int) $item['resource_id'],
                        'current_revision' => $item['revision'],
                    ];
                }
            }

            $preserved = $this->capture($novel, $user, 'Antes de restaurar '.$snapshot->name);
            $snapshotItems = collect($snapshot->items)->keyBy(fn ($item) => $item->resource_type.':'.$item->resource_id);

            foreach ($current['items'] as $item) {
                if (! in_array($item['resource_type'], self::RESTORE_TYPES, true)) {
                    continue;
                }

                $source = $snapshotItems->get($item['resource_type'].':'.$item['resource_id']);

                if ($source === null || $source->payload != $item['payload']) {
                    $class = self::TYPES[$item['resource_type']][0];
                    $model = $class::find($item['resource_id']);

                    if ($model) {
                        $this->recordHistory($novel->id, $item['resource_type'], $model, $user, 'full_restore');
                    }
                }
            }

            $this->applyRestore($snapshot, $novel);

            return [
                'stale' => false,
                'preserved_snapshot_id' => $preserved->id,
                'changed' => count($snapshot->items),
            ];
        });
    }

    public function recordHistory(int $novelId, string $type, $model, User $user, string $reason): void
    {
        if ($type === 'scene') {
            $this->history->record((int) $model->id, (int) $model->revision, $model->content ?? [], $user->id, $reason);

            return;
        }

        DB::table('history_entries')->insert([
            'novel_id' => $novelId,
            'resource_type' => $type,
            'resource_id' => $model->id,
            'revision' => (int) ($model->revision ?? 0),
            'payload' => json_encode($this->payload($type, $model)),
            'reason' => $reason,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
    }

    private function applyRestore(Snapshot $snapshot, Novel $novel): void
    {
        $items = collect($snapshot->items)->groupBy('resource_type');
        $ids = fn (string $type) => $items->get($type, collect())->pluck('resource_id')->map(fn ($id) => (int) $id)->all();

        Style::where('novel_id', $novel->id)->whereNotIn('id', $ids('style'))->delete();

        foreach (self::RESTORE_TYPES as $type) {
            if ($type === 'novel') {
                continue;
            }

            [$class, $fields, $revisioned] = self::TYPES[$type];

            foreach ($items->get($type, collect()) as $item) {
                $model = $class::withoutGlobalScope('trashed')->find($item->resource_id);
                $attributes = [];

                foreach ($fields as $field) {
                    $attributes[$field] = $item->payload[$field] ?? null;
                }

                if ($model) {
                    if ($revisioned) {
                        $attributes['revision'] = (int) $model->revision + 1;
                    }

                    $model->forceFill($attributes)->save();
                } else {
                    $attributes['id'] = (int) $item->resource_id;

                    if ($revisioned) {
                        $attributes['revision'] = max(1, (int) $item->revision);
                    }

                    $class::forceCreate($attributes);
                }

                DB::table('trash_entries')
                    ->where('resource_type', $type)
                    ->where('resource_id', $item->resource_id)
                    ->delete();
            }
        }

        $novel->forceFill([
            'title' => $snapshot->manifest['novel']['title'] ?? $novel->title,
            'settings' => $snapshot->manifest['novel']['settings'] ?? $novel->settings,
            'revision' => (int) $novel->revision + 1,
        ])->save();

        $chapterIds = Chapter::withoutGlobalScope('trashed')->where('novel_id', $novel->id)->pluck('id')->all();
        $alternativeIds = ChapterAlternative::whereIn('chapter_id', $chapterIds)->pluck('id')->all();

        Scene::whereIn('chapter_alternative_id', $alternativeIds)->whereNotIn('id', $ids('scene'))->delete();
        ChapterAlternative::whereIn('chapter_id', $chapterIds)->whereNotIn('id', $ids('chapter_alternative'))->delete();
        Chapter::withoutGlobalScope('trashed')->where('novel_id', $novel->id)->whereNotIn('id', $ids('chapter'))->delete();
        Part::where('novel_id', $novel->id)->whereNotIn('id', $ids('part'))->delete();
        WorkState::where('novel_id', $novel->id)->whereNotIn('id', $ids('work_state'))->delete();
    }

    private function item(string $type, $model, array $payload): array
    {
        return [
            'resource_type' => $type,
            'resource_id' => (int) $model->id,
            'revision' => (int) ($model->revision ?? 0),
            'payload' => $payload,
        ];
    }

    private function payload(string $type, $model): array
    {
        $data = [];

        foreach (self::TYPES[$type][1] as $field) {
            $data[$field] = $model->{$field};
        }

        return $data;
    }

    private function changedFields(array $from, array $to): array
    {
        $keys = array_unique(array_merge(array_keys($from), array_keys($to)));

        return array_values(array_filter(
            $keys,
            fn ($key) => ($from[$key] ?? null) != ($to[$key] ?? null),
        ));
    }

    private function blockById(array $blocks, string $blockId): ?array
    {
        foreach ($blocks as $block) {
            if (($block['id'] ?? null) === $blockId) {
                return $block;
            }
        }

        return null;
    }

    private function blockIndex(array $blocks, string $blockId): ?int
    {
        foreach ($blocks as $index => $block) {
            if (($block['id'] ?? null) === $blockId) {
                return $index;
            }
        }

        return null;
    }

    private function fontsFor(Novel $novel)
    {
        $profile = BookProfile::where('novel_id', $novel->id)->latest('id')->first();
        $sources = [$novel->settings ?? [], $profile?->fonts ?? [], $profile?->cover ?? []];

        foreach ($novel->styles as $style) {
            $sources[] = $style->definition ?? [];
        }

        foreach ($novel->chapters as $chapter) {
            foreach ($chapter->alternatives as $alternative) {
                foreach ($alternative->scenes as $scene) {
                    $sources[] = $scene->content ?? [];
                }
            }
        }

        $ids = [];
        array_walk_recursive($sources, function ($value, $key) use (&$ids) {
            if (is_string($key)
                && str_contains(strtolower($key), 'font')
                && (is_int($value) || ctype_digit((string) $value))) {
                $ids[] = (int) $value;
            }
        });

        if ($ids === []) {
            return collect();
        }

        return Font::whereIn('id', array_values(array_unique($ids)))
            ->where('owner_id', $novel->owner_id)
            ->get();
    }

    private function mediaFor(Novel $novel, ?BookProfile $profile)
    {
        $sources = [$profile?->cover ?? []];

        foreach ($novel->chapters as $chapter) {
            foreach ($chapter->alternatives as $alternative) {
                foreach ($alternative->scenes as $scene) {
                    $sources[] = $scene->content ?? [];
                }
            }
        }

        $ids = [];
        array_walk_recursive($sources, function ($value, $key) use (&$ids) {
            $key = is_string($key) ? strtolower($key) : '';

            if ((str_contains($key, 'asset') || str_contains($key, 'image') || str_contains($key, 'media'))
                && (is_int($value) || ctype_digit((string) $value))) {
                $ids[] = (int) $value;
            }
        });

        if ($ids === []) {
            return collect();
        }

        return MediaAsset::whereIn('id', array_values(array_unique($ids)))
            ->where('owner_id', $novel->owner_id)
            ->get();
    }

    private function referencedCards(Novel $novel): array
    {
        $ids = [];

        foreach ($novel->chapters as $chapter) {
            foreach ($chapter->alternatives as $alternative) {
                foreach ($alternative->scenes as $scene) {
                    foreach ([$scene->pov_card_id, $scene->place_card_id] as $cardId) {
                        if ($cardId) {
                            $ids[] = (int) $cardId;
                        }
                    }
                }
            }
        }

        $sceneIds = DB::table('scenes')
            ->join('chapter_alternatives', 'chapter_alternatives.id', '=', 'scenes.chapter_alternative_id')
            ->join('chapters', 'chapters.id', '=', 'chapter_alternatives.chapter_id')
            ->where('chapters.novel_id', $novel->id)
            ->pluck('scenes.id')
            ->all();

        if ($sceneIds !== []) {
            $ids = array_merge($ids, DB::table('text_references')
                ->whereIn('scene_id', $sceneIds)
                ->pluck('card_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        if ($ids === []) {
            return [];
        }

        return Card::whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->map(fn (Card $card) => [
                'id' => $card->id,
                'area' => $card->area,
                'name' => $card->name,
                'revision' => (int) $card->revision,
            ])
            ->all();
    }

    private function assertBelongs(string $type, $model, int $novelId): void
    {
        $belongs = match ($type) {
            'novel' => (int) $model->id,
            'part', 'work_state', 'chapter', 'style' => (int) $model->novel_id,
            'chapter_alternative' => (int) Chapter::findOrFail($model->chapter_id)->novel_id,
            'scene' => (int) Chapter::findOrFail(
                ChapterAlternative::findOrFail($model->chapter_alternative_id)->chapter_id
            )->novel_id,
        };

        abort_unless($belongs === $novelId, 404);
    }
}
