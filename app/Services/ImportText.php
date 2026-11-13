<?php

namespace App\Services;

class ImportText
{
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function looksLikeHeading(string $line): bool
    {
        $line = trim($line);
        $length = mb_strlen($line, 'UTF-8');

        if ($line === '' || $length > 140) {
            return false;
        }

        if (preg_match(
            '/^(?:#{1,6}\s+|(?:cap[ií]tulo|chapter|parte|part|pr[oó]logo|prologue|ep[ií]logo|epilogue)\b)/iu',
            $line,
        ) === 1) {
            return true;
        }

        return $length <= 80
            && preg_match('/^[\p{Lu}\p{N}\s.,:;¡!¿?\'’"«»-]+$/u', $line) === 1
            && preg_match('/\p{Lu}/u', $line) === 1;
    }

    public static function chapters(string $text, string $defaultTitle): array
    {
        $chapters = [];
        $current = ['title' => $defaultTitle, 'lines' => []];

        foreach (explode("\n", self::normalize($text)) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (self::looksLikeHeading($trimmed)) {
                if ($current['lines'] !== [] || $chapters !== []) {
                    $chapters[] = $current;
                    $current = ['title' => $trimmed, 'lines' => []];
                } else {
                    $current['title'] = $trimmed;
                }

                continue;
            }

            $current['lines'][] = $trimmed;
        }

        $chapters[] = $current;
        $chapters = array_values(array_filter($chapters, fn (array $chapter): bool => $chapter['lines'] !== []));

        if ($chapters === []) {
            $chapters[] = ['title' => $defaultTitle, 'lines' => []];
        }

        return array_map(fn (array $chapter): array => [
            'title' => $chapter['title'],
            'text' => implode("\n", $chapter['lines']),
        ], $chapters);
    }
}
