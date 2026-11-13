<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TxtImport
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function preview(string $path, string $originalName): array
    {
        $bytes = (int) @filesize($path);

        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'El TXT debe pesar entre 1 byte y 10 MiB.',
            ]);
        }

        $raw = (string) file_get_contents($path);

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (str_contains($raw, "\0") || ! mb_check_encoding($raw, 'UTF-8')) {
            throw ValidationException::withMessages([
                'file' => 'El TXT debe usar codificación UTF-8 válida.',
            ]);
        }

        $title = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Manuscrito';
        $chapters = ImportText::chapters($raw, $title);

        return [
            'source' => [
                'name' => basename($originalName),
                'bytes' => $bytes,
                'format' => 'txt',
                'encoding' => 'UTF-8',
            ],
            'chapters' => $chapters,
            'inventory' => [
                'headings' => array_values(array_filter(array_column($chapters, 'title'))),
                'tables' => 0,
                'images' => 0,
                'footnotes' => 0,
                'endnotes' => 0,
            ],
            'unsupported' => [],
            'losses' => [],
        ];
    }
}
