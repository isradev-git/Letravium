<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ContentDocument
{
    public const SCHEMA_VERSION = 1;

    public const BREAK_TYPES = [
        'scene_break', 'section_break', 'page_break',
    ];

    public const BLOCK_TYPES = [
        'paragraph', 'heading', 'quote', 'list_item',
        'scene_break', 'section_break', 'page_break',
        'table', 'image',
    ];

    public const TEXT_TYPES = ['paragraph', 'heading', 'quote', 'list_item'];

    public const IMAGE_ALIGNMENTS = ['left', 'center', 'right'];

    public const LIST_TYPES = ['bullet', 'ordered'];

    public const MARK_TYPES = [
        'bold', 'italic', 'underline', 'strike', 'superscript', 'subscript',
        'link', 'character_style', 'footnote_reference', 'font_family', 'font_size',
    ];

    public function validate(array $document): array
    {
        if (($document['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $this->fail('schema_version');
        }

        if (! isset($document['blocks']) || ! is_array($document['blocks'])) {
            $this->fail('blocks');
        }

        $seen = [];
        $bookmarks = [];

        foreach ($document['blocks'] as $index => $block) {
            if (! is_array($block)) {
                $this->fail("blocks.{$index}");
            }

            $type = $block['type'] ?? null;

            if (! in_array($type, self::BLOCK_TYPES, true)) {
                $this->fail("blocks.{$index}.type");
            }

            $id = $block['id'] ?? null;

            if (! is_string($id) || $id === '' || isset($seen[$id])) {
                $this->fail("blocks.{$index}.id");
            }

            $seen[$id] = true;

            $isText = in_array($type, self::TEXT_TYPES, true);

            if (! $isText && isset($block['text']) && $block['text'] !== '') {
                $this->fail("blocks.{$index}.text");
            }

            if ($isText && (! isset($block['text']) || ! is_string($block['text']))) {
                $this->fail("blocks.{$index}.text");
            }

            $this->validateFormatting($block, $index, $isText);

            if ($type === 'table') {
                $this->validateTable($block, $index, $seen);
            }

            if ($type === 'image') {
                $this->validateImage($block, $index);
            }

            if ($type === 'heading' && isset($block['level'])) {
                $level = $block['level'];

                if (! is_int($level) || $level < 1 || $level > 6) {
                    $this->fail("blocks.{$index}.level");
                }
            }

            if ($type === 'list_item') {
                if (isset($block['list_type']) && ! in_array($block['list_type'], self::LIST_TYPES, true)) {
                    $this->fail("blocks.{$index}.list_type");
                }

                if (isset($block['depth'])
                    && (! is_int($block['depth']) || $block['depth'] < 0 || $block['depth'] > 5)) {
                    $this->fail("blocks.{$index}.depth");
                }
            }

            if (isset($block['bookmark'])) {
                $bookmark = $block['bookmark'];

                if (! is_string($bookmark) || trim($bookmark) === '' || isset($bookmarks[$bookmark])) {
                    $this->fail("blocks.{$index}.bookmark");
                }

                $bookmarks[$bookmark] = true;
            }

            if (isset($block['marks'])) {
                if (! $isText) {
                    $this->fail("blocks.{$index}.marks");
                }

                $this->validateMarks($block, $index);
            }

            if (isset($block['references']) && ! is_array($block['references'])) {
                $this->fail("blocks.{$index}.references");
            }
        }

        return $document;
    }

    public function validateNote(array $document): array
    {
        $this->validate($document);

        foreach ($document['blocks'] as $index => $block) {
            if (! in_array($block['type'] ?? null, self::TEXT_TYPES, true)) {
                $this->fail("blocks.{$index}.type");
            }

            foreach (($block['marks'] ?? []) as $position => $mark) {
                if (($mark['type'] ?? null) === 'footnote_reference') {
                    $this->fail("blocks.{$index}.marks.{$position}");
                }
            }
        }

        $text = trim($this->text($document));

        if ($text === '' || mb_strlen($text, 'UTF-8') > 20000) {
            $this->fail('blocks');
        }

        return $document;
    }

    public function text(array $document): string
    {
        $parts = [];

        foreach (($document['blocks'] ?? []) as $block) {
            if (isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    public function wordCount(array $document): int
    {
        preg_match_all(
            "/[\\p{L}\\p{N}]+(?:['’\\-][\\p{L}\\p{N}]+)*/u",
            $this->text($document),
            $matches,
        );

        return count($matches[0]);
    }

    private function validateMarks(array $block, int|string $index): void
    {
        $marks = $block['marks'];

        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->fail("blocks.{$index}.marks");
        }

        if ($marks === []) {
            return;
        }

        if (! in_array($block['type'] ?? null, self::TEXT_TYPES, true)) {
            $this->fail("blocks.{$index}.marks");
        }

        $textLength = is_string($block['text'] ?? null)
            ? mb_strlen($block['text'], 'UTF-8')
            : null;

        foreach ($marks as $position => $mark) {
            $field = "blocks.{$index}.marks.{$position}";

            if (! is_array($mark) || ! in_array($mark['type'] ?? null, self::MARK_TYPES, true)) {
                $this->fail($field);
            }

            if (isset($mark['attrs']) && ! is_array($mark['attrs'])) {
                $this->fail("{$field}.attrs");
            }

            if (isset($mark['start']) || isset($mark['end'])) {
                if (! is_int($mark['start'] ?? null) || ! is_int($mark['end'] ?? null)
                    || $mark['start'] < 0 || $mark['end'] <= $mark['start']
                    || $textLength === null || $mark['end'] > $textLength) {
                    $this->fail($field);
                }
            }

            if ($mark['type'] === 'link') {
                $href = $mark['attrs']['href'] ?? null;

                if (! is_string($href) || ! preg_match('#^https?://#i', $href)
                    || preg_match('/[\x00-\x20\x7f]/', $href)) {
                    $this->fail("{$field}.attrs.href");
                }
            }

            if ($mark['type'] === 'character_style') {
                $id = $mark['attrs']['id'] ?? $mark['attrs']['style_id'] ?? null;

                if (! is_string($id) || $id === '') {
                    $this->fail("{$field}.attrs.id");
                }
            }

            if ($mark['type'] === 'font_family') {
                $family = $mark['attrs']['family'] ?? null;

                if (! $this->isFontFamily($family)) {
                    $this->fail("{$field}.attrs.family");
                }
            }

            if ($mark['type'] === 'font_size') {
                $size = $mark['attrs']['size'] ?? null;

                if (! is_int($size) || $size < 8 || $size > 72) {
                    $this->fail("{$field}.attrs.size");
                }
            }

            if ($mark['type'] === 'footnote_reference') {
                $noteId = $mark['attrs']['note_id'] ?? null;

                if (! is_int($noteId) || $noteId < 1) {
                    $this->fail("{$field}.attrs.note_id");
                }
            }
        }
    }

    private function validateFormatting(array $block, int|string $index, bool $isText): void
    {
        $keys = [
            'align', 'font_family', 'font_size', 'indent_first',
            'indent_left', 'line_height', 'spacing_before', 'spacing_after',
        ];

        if (! $isText) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $block)) {
                    $this->fail("blocks.{$index}.{$key}");
                }
            }

            return;
        }

        if (isset($block['align']) && ! in_array($block['align'], ['left', 'center', 'right', 'justify'], true)) {
            $this->fail("blocks.{$index}.align");
        }

        if (isset($block['font_family']) && ! $this->isFontFamily($block['font_family'])) {
            $this->fail("blocks.{$index}.font_family");
        }

        $ranges = [
            'font_size' => [8, 72],
            'indent_first' => [-6, 6],
            'indent_left' => [0, 6],
            'line_height' => [100, 300],
            'spacing_before' => [0, 72],
            'spacing_after' => [0, 72],
        ];

        foreach ($ranges as $key => [$min, $max]) {
            if (! isset($block[$key])) {
                continue;
            }

            if (! is_int($block[$key]) || $block[$key] < $min || $block[$key] > $max) {
                $this->fail("blocks.{$index}.{$key}");
            }
        }
    }

    private function validateTable(array $block, int|string $index, array &$seen): void
    {
        if (isset($block['caption']) && ! is_string($block['caption'])) {
            $this->fail("blocks.{$index}.caption");
        }

        $rows = $block['rows'] ?? null;

        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            $this->fail("blocks.{$index}.rows");
        }

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                $this->fail("blocks.{$index}.rows.{$rowIndex}");
            }

            $rowId = $row['id'] ?? null;

            if (! is_string($rowId) || $rowId === '' || isset($seen[$rowId])) {
                $this->fail("blocks.{$index}.rows.{$rowIndex}.id");
            }

            $seen[$rowId] = true;

            if (isset($row['header']) && ! is_bool($row['header'])) {
                $this->fail("blocks.{$index}.rows.{$rowIndex}.header");
            }

            $cells = $row['cells'] ?? null;

            if (! is_array($cells) || $cells === [] || ! array_is_list($cells)) {
                $this->fail("blocks.{$index}.rows.{$rowIndex}.cells");
            }

            foreach ($cells as $cellIndex => $cell) {
                if (! is_array($cell)) {
                    $this->fail("blocks.{$index}.rows.{$rowIndex}.cells.{$cellIndex}");
                }

                $cellId = $cell['id'] ?? null;

                if (! is_string($cellId) || $cellId === '' || isset($seen[$cellId])) {
                    $this->fail("blocks.{$index}.rows.{$rowIndex}.cells.{$cellIndex}.id");
                }

                $seen[$cellId] = true;

                if (! isset($cell['text']) || ! is_string($cell['text'])) {
                    $this->fail("blocks.{$index}.rows.{$rowIndex}.cells.{$cellIndex}.text");
                }
            }
        }
    }

    private function validateImage(array $block, int|string $index): void
    {
        $attrs = $block['attrs'] ?? null;

        if (! is_array($attrs)) {
            $this->fail("blocks.{$index}.attrs");
        }

        $assetId = $attrs['asset_id'] ?? null;

        if (! is_int($assetId) || $assetId < 1) {
            $this->fail("blocks.{$index}.attrs.asset_id");
        }

        foreach (['alt', 'caption'] as $key) {
            if (isset($attrs[$key]) && ! is_string($attrs[$key])) {
                $this->fail("blocks.{$index}.attrs.{$key}");
            }
        }

        foreach (['width', 'height'] as $key) {
            if (isset($attrs[$key]) && (! is_int($attrs[$key]) || $attrs[$key] < 1)) {
                $this->fail("blocks.{$index}.attrs.{$key}");
            }
        }

        if (isset($attrs['alignment']) && ! in_array($attrs['alignment'], self::IMAGE_ALIGNMENTS, true)) {
            $this->fail("blocks.{$index}.attrs.alignment");
        }
    }

    private function isFontFamily(mixed $family): bool
    {
        return $family === 'serif'
            || $family === 'sans'
            || (is_string($family) && preg_match('/^font:[1-9]\d*$/', $family) === 1);
    }

    private function fail(string $field): void
    {
        throw ValidationException::withMessages([
            'content' => "Documento no válido en: {$field}.",
        ]);
    }
}
