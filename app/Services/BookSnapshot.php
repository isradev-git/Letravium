<?php

namespace App\Services;

use App\Models\BookMetadata;
use App\Models\BookProfile;
use App\Models\BookSection;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Models\Style;
use Illuminate\Support\Facades\Storage;

class BookSnapshot
{
    public function __construct(private BookNavigation $navigation) {}

    public function build(Novel $novel, ?BookProfile $profile = null): array
    {
        $profile ??= $novel->hasMany(BookProfile::class)->latest('id')->first();

        $metadata = BookMetadata::where('novel_id', $novel->id)->first();

        $sections = $novel->hasMany(BookSection::class)
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

        $sceneToChapter = [];
        $chapters = [];

        foreach ($novel->chapters()->orderBy('position')->get() as $chapter) {
            $active = $chapter->alternatives()->where('is_active', true)->first();
            $scenes = [];
            $blocks = [];

            if ($active) {
                foreach ($active->scenes()->orderBy('position')->get() as $scene) {
                    $sceneToChapter[$scene->id] = $chapter->id;
                    $content = is_array($scene->content) ? $scene->content : [];
                    $scenes[] = [
                        'id' => $scene->id,
                        'title' => $scene->title,
                        'position' => $scene->position,
                        'revision' => (int) $scene->revision,
                        'content' => $content,
                    ];

                    foreach (($content['blocks'] ?? []) as $block) {
                        if (is_array($block)) {
                            $block['scene_id'] = $scene['id'];
                        }

                        $blocks[] = $block;
                    }
                }
            }

            $chapters[] = [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'position' => $chapter->position,
                'revision' => (int) $chapter->revision,
                'alternative_revision' => $active ? (int) $active->revision : null,
                'part_id' => $chapter->part_id,
                'scenes' => $scenes,
                'blocks' => $blocks,
                'notes' => [],
            ];
        }

        $chapterIndex = [];
        foreach ($chapters as $index => $chapter) {
            $chapterIndex[$chapter['id']] = $index;
        }

        $notes = Footnote::where('novel_id', $novel->id)
            ->orderBy('kind')
            ->orderBy('number')
            ->get()
            ->map(function (Footnote $note) use ($sceneToChapter, &$chapters, $chapterIndex) {
                $chapterId = $note->scene_id ? ($sceneToChapter[$note->scene_id] ?? null) : null;
                $entry = [
                    'id' => $note->id,
                    'kind' => $note->kind,
                    'number' => $note->number,
                    'text' => $note->text,
                    'content' => $note->content,
                    'scene_id' => $note->scene_id,
                    'chapter_id' => $chapterId,
                ];

                if ($chapterId !== null && isset($chapterIndex[$chapterId])) {
                    $chapters[$chapterIndex[$chapterId]]['notes'][] = $entry;
                }

                return $entry;
            })->all();

        $styles = Style::where('novel_id', $novel->id)->orderBy('kind')->orderBy('name')->get()
            ->map(fn (Style $style) => [
                'id' => $style->id,
                'name' => $style->name,
                'kind' => $style->kind,
                'definition' => $style->definition,
            ])->all();

        $fontIds = $this->fontIds($profile, $styles);
        $fonts = Font::whereIn('id', $fontIds)->where('owner_id', $novel->owner_id)->get()->map(fn (Font $font) => [
            'id' => $font->id,
            'family' => $font->family,
            'variant' => $font->variant,
            'license' => $font->license,
            'path' => $font->path,
            'bytes' => $font->bytes,
            'metadata' => $font->metadata,
            'exists' => Storage::disk('local')->exists($font->path),
        ])->values()->all();

        $assetIds = $this->assetIds($chapters, $profile?->cover);
        $media = MediaAsset::whereIn('id', $assetIds)->where('owner_id', $novel->owner_id)->get()->map(fn (MediaAsset $asset) => [
            'id' => $asset->id,
            'original_name' => $asset->original_name,
            'mime' => $asset->mime,
            'path' => $asset->path,
            'bytes' => $asset->bytes,
            'metadata' => $asset->metadata,
            'exists' => Storage::disk('local')->exists($asset->path),
        ])->values()->all();

        return [
            'schema_version' => 1,
            'novel' => ['id' => $novel->id, 'title' => $novel->title, 'revision' => (int) $novel->revision],
            'metadata' => [
                'title' => $metadata?->title ?: $novel->title,
                'subtitle' => $metadata?->subtitle,
                'author' => $metadata?->author,
                'language' => $metadata?->language ?: 'es',
                'description' => $metadata?->description,
                'rights' => $metadata?->rights,
                'edition' => $metadata?->edition,
                'publisher' => $metadata?->publisher,
                'isbn' => $metadata?->isbn,
            ],
            'profile' => $profile?->toArray(),
            'sections' => $sections,
            'chapters' => $chapters,
            'notes' => $notes,
            'navigation' => $this->navigation->build($novel),
            'resources' => [
                'styles' => $styles,
                'fonts' => $fonts,
                'media' => $media,
            ],
        ];
    }

    public function buildFromSnapshot(Novel $novel, Snapshot $snapshot, ?BookProfile $profile = null): array
    {
        $saved = $snapshot->manifest;
        $profile ??= $this->profileFromSnapshot($snapshot);
        $manifest = $this->build($novel, $profile);
        $liveNotesByChapter = collect($manifest['chapters'])->keyBy('id');

        $chapters = [];
        $sceneToChapter = [];

        foreach ($saved['chapters'] ?? [] as $chapter) {
            $active = collect($chapter['alternatives'] ?? [])->firstWhere('is_active', true);
            $scenes = [];
            $blocks = [];

            foreach (($active['scenes'] ?? []) as $scene) {
                $sceneToChapter[$scene['id']] = $chapter['id'];
                $content = is_array($scene['content'] ?? null) ? $scene['content'] : [];
                $scenes[] = [
                    'id' => $scene['id'],
                    'title' => $scene['title'],
                    'position' => $scene['position'],
                    'revision' => (int) ($scene['revision'] ?? 0),
                    'content' => $content,
                ];

                foreach (($content['blocks'] ?? []) as $block) {
                    if (is_array($block)) {
                        $block['scene_id'] = $scene['id'];
                    }

                    $blocks[] = $block;
                }
            }

            $chapters[] = [
                'id' => $chapter['id'],
                'title' => $chapter['title'],
                'position' => $chapter['position'],
                'revision' => (int) ($chapter['revision'] ?? 0),
                'alternative_revision' => $active ? (int) ($active['revision'] ?? 0) : null,
                'part_id' => $chapter['part_id'] ?? null,
                'scenes' => $scenes,
                'blocks' => $blocks,
                'notes' => $liveNotesByChapter[$chapter['id']]['notes'] ?? [],
            ];
        }

        $manifest['chapters'] = $chapters;
        $manifest['novel'] = $saved['novel'] ?? $manifest['novel'];

        if (array_key_exists('notes', $saved)) {
            $notes = [];
            $notesByChapter = [];

            foreach ($saved['notes'] as $note) {
                $chapterId = $note['scene_id'] ? ($sceneToChapter[$note['scene_id']] ?? null) : null;
                $entry = $note + ['chapter_id' => $chapterId];
                $notes[] = $entry;

                if ($chapterId !== null) {
                    $notesByChapter[$chapterId][] = $entry;
                }
            }

            $manifest['notes'] = $notes;

            foreach ($manifest['chapters'] as $index => $chapter) {
                $manifest['chapters'][$index]['notes'] = $notesByChapter[$chapter['id']] ?? [];
            }
        }

        if (array_key_exists('metadata', $saved)) {
            $manifest['metadata'] = $this->metadataShape($saved['metadata'] ?? [], $novel);
        }

        if (array_key_exists('sections', $saved)) {
            $manifest['sections'] = $saved['sections'];
        }

        if (! empty($saved['navigation'])) {
            $manifest['navigation'] = $saved['navigation'];
        }

        if (! empty($saved['styles'])) {
            $manifest['resources']['styles'] = $saved['styles'];
        }

        if (! empty($saved['fonts'])) {
            $fontIds = array_map(fn (array $font): int => (int) ($font['id'] ?? 0), $saved['fonts']);
            $ownedFonts = Font::where('owner_id', $novel->owner_id)
                ->whereIn('id', $fontIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $manifest['resources']['fonts'] = array_values(array_filter(
                array_map(function (array $font) {
                    $font['exists'] = isset($font['path'])
                        && Storage::disk('local')->exists((string) $font['path']);

                    return $font;
                }, $saved['fonts']),
                fn (array $font): bool => in_array((int) ($font['id'] ?? 0), $ownedFonts, true),
            ));
        }

        if (! empty($saved['media'])) {
            $mediaIds = array_map(fn (array $asset): int => (int) ($asset['id'] ?? 0), $saved['media']);
            $ownedMedia = MediaAsset::where('owner_id', $novel->owner_id)
                ->whereIn('id', $mediaIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $manifest['resources']['media'] = array_values(array_filter(
                array_map(function (array $asset) {
                    $asset['exists'] = isset($asset['path'])
                        && Storage::disk('local')->exists((string) $asset['path']);

                    return $asset;
                }, $saved['media']),
                fn (array $asset): bool => in_array((int) ($asset['id'] ?? 0), $ownedMedia, true),
            ));
        }

        return $manifest;
    }

    private function profileFromSnapshot(Snapshot $snapshot): ?BookProfile
    {
        $data = $snapshot->manifest['profile'] ?? null;

        return is_array($data) ? (new BookProfile)->forceFill($data) : null;
    }

    private function metadataShape(array $metadata, Novel $novel): array
    {
        return [
            'title' => ($metadata['title'] ?? null) ?: $novel->title,
            'subtitle' => $metadata['subtitle'] ?? null,
            'author' => $metadata['author'] ?? null,
            'language' => ($metadata['language'] ?? null) ?: 'es',
            'description' => $metadata['description'] ?? null,
            'rights' => $metadata['rights'] ?? null,
            'edition' => $metadata['edition'] ?? null,
            'publisher' => $metadata['publisher'] ?? null,
            'isbn' => $metadata['isbn'] ?? null,
        ];
    }

    private function fontIds(?BookProfile $profile, array $styles): array
    {
        $ids = [];

        if ($profile) {
            $fonts = $profile->fonts ?? [];

            if (is_array($fonts) && array_is_list($fonts)) {
                foreach ($fonts as $value) {
                    $this->addId($ids, $value);
                }
            }

            $this->walk($fonts, function ($key, $value) use (&$ids) {
                if (is_string($key) && str_contains(strtolower($key), 'font')) {
                    $this->addId($ids, $value);
                }
            });
        }

        $this->walk($styles, function ($key, $value) use (&$ids) {
            if (is_string($key) && str_contains(strtolower($key), 'font')) {
                $this->addId($ids, $value);
            }
        });

        return array_values(array_unique($ids));
    }

    private function assetIds(array $chapters, ?array $cover): array
    {
        $ids = [];

        $collector = function ($key, $value) use (&$ids) {
            if (is_string($key)
                && (str_contains(strtolower($key), 'asset')
                    || str_contains(strtolower($key), 'image')
                    || str_contains(strtolower($key), 'media'))) {
                $this->addId($ids, $value);
            }
        };

        $this->walk($chapters, $collector);
        $this->walk($cover ?? [], $collector);

        return array_values(array_unique($ids));
    }

    private function addId(array &$ids, mixed $value): void
    {
        if (is_int($value) && $value > 0) {
            $ids[] = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $ids[] = (int) $value;
        } elseif (is_array($value) && isset($value['id']) && is_numeric($value['id'])) {
            $ids[] = (int) $value['id'];
        }
    }

    private function walk(mixed $value, callable $visit, ?string $key = null): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $visit($childKey, $child);
                $this->walk($child, $visit, is_string($childKey) ? $childKey : null);
            }
        }
    }
}
