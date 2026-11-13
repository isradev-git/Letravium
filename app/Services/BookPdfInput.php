<?php

namespace App\Services;

use Illuminate\Support\Str;

class BookPdfInput
{
    public function build(array $manifest, int $manifestId): array
    {
        $notesById = $this->notesById($manifest);
        $sceneToChapter = $this->sceneToChapter($manifest);
        $chapterTitles = [];

        foreach (($manifest['chapters'] ?? []) as $chapter) {
            $chapterTitles[(int) $chapter['id']] = (string) ($chapter['title'] ?? '');
        }

        $chapters = [];
        $mediaIds = [];

        foreach (($manifest['chapters'] ?? []) as $chapter) {
            $blocks = [];

            foreach (($chapter['blocks'] ?? []) as $block) {
                $mapped = $this->block($block, $notesById, $mediaIds);

                if ($mapped !== null) {
                    if (isset($block['scene_id'])) {
                        $mapped['scene_id'] = (int) $block['scene_id'];
                    }

                    $blocks[] = $mapped;
                }
            }

            $chapters[] = [
                'id' => (int) ($chapter['id'] ?? 0),
                'title' => (string) ($chapter['title'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        $index = [];
        $figureTargets = collect($manifest['navigation']['figures'] ?? [])->keyBy('id')->all();
        $tableTargets = collect($manifest['navigation']['tables'] ?? [])->keyBy('id')->all();

        foreach (($manifest['navigation']['index'] ?? []) as $entry) {
            $term = (string) ($entry['label'] ?? '');
            $target = $this->indexRef($entry, $chapterTitles, $sceneToChapter, $figureTargets, $tableTargets);

            if ($term !== '' && $target !== null) {
                $index[] = [
                    'term' => $term,
                    'ref' => $target['ref'],
                    'page_ref' => $target['page_ref'],
                ];
            }
        }

        [$bodyFontId, $headingFontId] = $this->fontIds($manifest);

        return [
            'input' => [
                'metadata' => $manifest['metadata'] ?? [],
                'cover' => [
                    'kicker' => 'Libro',
                    'note' => (string) ($manifest['metadata']['publisher'] ?? ''),
                ],
                'chapters' => $chapters,
                'sections' => $this->sections($manifest['sections'] ?? []),
                'index' => $index,
                'layout' => $this->layout($manifest['profile'] ?? []),
            ],
            'fonts' => [
                'body' => $bodyFontId !== null ? $this->resourceUrl($manifestId, 'font', $bodyFontId) : null,
                'heading' => $headingFontId !== null ? $this->resourceUrl($manifestId, 'font', $headingFontId) : null,
            ],
            'media' => $this->mediaUrls($manifest, $manifestId, array_keys($mediaIds)),
            'filename' => (Str::slug((string) ($manifest['metadata']['title'] ?? 'libro')) ?: 'libro').'.pdf',
        ];
    }

    private function block(array $block, array $notesById, array &$mediaIds): ?array
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        if (in_array($type, ['scene_break', 'section_break'], true)) {
            return ['type' => 'paragraph', 'text' => '* * *'];
        }

        if ($type === 'page_break') {
            return ['type' => 'page_break'];
        }

        if ($type === 'table') {
            $headers = [];
            $rows = [];

            foreach (($block['rows'] ?? []) as $row) {
                $cells = array_map(
                    fn ($cell) => (string) ($cell['text'] ?? ''),
                    $row['cells'] ?? [],
                );

                if ($headers === [] && ($row['header'] ?? false) === true) {
                    $headers = $cells;

                    continue;
                }

                $rows[] = $cells;
            }

            if ($headers === [] && $rows === []) {
                return null;
            }

            return [
                'type' => 'table',
                'caption' => (string) ($block['caption'] ?? ''),
                'headers' => $headers,
                'rows' => $rows,
            ];
        }

        $assetId = (int) ($block['attrs']['asset_id'] ?? $block['image_asset_id'] ?? 0);

        if ($type === 'image' || $assetId > 0) {
            if ($assetId < 1) {
                return null;
            }

            $mediaIds[$assetId] = true;

            return [
                'type' => 'image',
                'asset_id' => $assetId,
                'alt' => (string) ($block['attrs']['alt'] ?? $block['text'] ?? ''),
                'caption' => (string) ($block['attrs']['caption'] ?? ''),
            ];
        }

        $text = (string) ($block['text'] ?? '');
        $blockNotes = [];
        $blockNoteNumbers = [];
        $insertions = [];

        foreach (($block['marks'] ?? []) as $mark) {
            if (($mark['type'] ?? null) !== 'footnote_reference') {
                continue;
            }

            $noteId = (int) ($mark['attrs']['note_id'] ?? 0);

            if ($noteId < 1 || ! isset($notesById[$noteId])) {
                continue;
            }

            $position = isset($mark['end'])
                ? (int) $mark['end']
                : (isset($mark['start']) ? (int) $mark['start'] : mb_strlen($text));
            $marker = 'F'.$noteId;
            $insertions[$position][] = '[[F'.$noteId.']]';
            $blockNotes[$marker] = (string) ($notesById[$noteId]['text'] ?? '');
            $blockNoteNumbers[$marker] = (int) ($notesById[$noteId]['number'] ?? 0);
        }

        krsort($insertions);

        foreach ($insertions as $position => $markers) {
            $text = mb_substr($text, 0, $position).implode('', $markers).mb_substr($text, $position);
        }

        $mapped = ['type' => $type, 'text' => $text];

        if ($type === 'heading') {
            $mapped['level'] = (int) ($block['level'] ?? 2);
        }

        if ($type === 'list_item') {
            $mapped['list_type'] = (string) ($block['list_type'] ?? 'bullet');
        }

        if ($blockNotes !== []) {
            $mapped['notes'] = $blockNotes;
            $mapped['note_numbers'] = $blockNoteNumbers;
        }

        return $mapped;
    }

    private function sections(array $sections): array
    {
        return array_values(array_map(fn (array $section): array => [
            'area' => in_array($section['area'] ?? null, ['front', 'back'], true) ? $section['area'] : 'front',
            'title' => (string) ($section['title'] ?? $section['type'] ?? ''),
            'content' => (string) ($section['content'] ?? ''),
        ], $sections));
    }

    private function layout(array $profile): array
    {
        return [
            'page_size' => $profile['page_size'] ?? null,
            'orientation' => $profile['orientation'] ?? 'portrait',
            'margins' => $profile['margins'] ?? [],
            'running_heads' => $profile['running_heads'] ?? [],
        ];
    }

    private function notesById(array $manifest): array
    {
        $notes = [];

        foreach (($manifest['notes'] ?? []) as $note) {
            $notes[(int) ($note['id'] ?? 0)] = $note;
        }

        foreach (($manifest['chapters'] ?? []) as $chapter) {
            foreach (($chapter['notes'] ?? []) as $note) {
                $notes[(int) ($note['id'] ?? 0)] = $note;
            }
        }

        return $notes;
    }

    private function sceneToChapter(array $manifest): array
    {
        $map = [];

        foreach (($manifest['chapters'] ?? []) as $chapter) {
            foreach (($chapter['scenes'] ?? []) as $scene) {
                $map[(int) ($scene['id'] ?? 0)] = (int) $chapter['id'];
            }

            foreach (($chapter['alternatives'] ?? []) as $alternative) {
                foreach (($alternative['scenes'] ?? []) as $scene) {
                    $map[(int) ($scene['id'] ?? 0)] = (int) $chapter['id'];
                }
            }
        }

        return $map;
    }

    private function indexRef(
        array $entry,
        array $chapterTitles,
        array $sceneToChapter,
        array $figureTargets = [],
        array $tableTargets = [],
    ): ?array {
        $targetType = $entry['target_type'] ?? null;
        $targetId = (int) ($entry['target_id'] ?? 0);

        if ($targetType === 'figure' && isset($figureTargets[$targetId])) {
            return $this->resolveTarget($figureTargets[$targetId], $chapterTitles, $sceneToChapter);
        }

        if ($targetType === 'table' && isset($tableTargets[$targetId])) {
            return $this->resolveTarget($tableTargets[$targetId], $chapterTitles, $sceneToChapter);
        }

        return $this->resolveTarget($entry, $chapterTitles, $sceneToChapter);
    }

    private function resolveTarget(array $target, array $chapterTitles, array $sceneToChapter): ?array
    {
        $targetType = $target['target_type'] ?? null;
        $targetId = (int) ($target['target_id'] ?? 0);

        if ($targetType === 'chapter' && isset($chapterTitles[$targetId])) {
            return ['ref' => $chapterTitles[$targetId], 'page_ref' => 'chapter:'.$targetId];
        }

        if ($targetType === 'scene' && isset($sceneToChapter[$targetId])) {
            return [
                'ref' => $chapterTitles[$sceneToChapter[$targetId]] ?? '',
                'page_ref' => 'scene:'.$targetId,
            ];
        }

        return null;
    }

    private function fontIds(array $manifest): array
    {
        $fonts = $manifest['resources']['fonts'] ?? [];
        $profileFonts = $manifest['profile']['fonts'] ?? [];
        $bodyId = $this->findFontId($profileFonts, ['body', 'texto', 'cuerpo']);
        $headingId = $this->findFontId($profileFonts, ['head', 'title', 'titulo', 'display']);
        $firstId = null;

        foreach ($fonts as $font) {
            if ($firstId === null && ! empty($font['exists'])) {
                $firstId = (int) $font['id'];
            }
        }

        $bodyId ??= $firstId;
        $headingId ??= $bodyId;

        $bodyId = $this->existingFontId($fonts, $bodyId);
        $headingId = $this->existingFontId($fonts, $headingId);

        return [$bodyId, $headingId];
    }

    private function existingFontId(array $fonts, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        foreach ($fonts as $font) {
            if ((int) ($font['id'] ?? 0) === $id) {
                return ! empty($font['exists']) ? $id : null;
            }
        }

        return null;
    }

    private function findFontId(array $value, array $needles): ?int
    {
        $found = null;

        array_walk_recursive($value, function ($item, $key) use (&$found, $needles): void {
            if ($found !== null || ! is_string($key)) {
                return;
            }

            $key = strtolower($key);

            if (! is_int($item) && ! ctype_digit((string) $item)) {
                return;
            }

            foreach ($needles as $needle) {
                if (str_contains($key, $needle)) {
                    $found = (int) $item;

                    return;
                }
            }
        });

        return $found;
    }

    private function mediaUrls(array $manifest, int $manifestId, array $ids): array
    {
        $available = [];

        foreach (($manifest['resources']['media'] ?? []) as $asset) {
            $available[(int) ($asset['id'] ?? 0)] = ! empty($asset['exists']);
        }

        $urls = [];

        foreach ($ids as $id) {
            if (! ($available[$id] ?? false)) {
                continue;
            }

            $urls[$id] = $this->resourceUrl($manifestId, 'media', $id);
        }

        return $urls;
    }

    private function resourceUrl(int $manifestId, string $kind, int $id): string
    {
        return url("/workspace/export-manifests/{$manifestId}/pdf-resource/{$kind}/{$id}");
    }
}
