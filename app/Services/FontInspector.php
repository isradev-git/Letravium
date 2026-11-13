<?php

namespace App\Services;

class FontInspector
{
    public function inspect(string $path): ?array
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || strlen($bytes) < 12) {
            return null;
        }

        $signature = substr($bytes, 0, 4);

        if (! in_array($signature, ["\x00\x01\x00\x00", 'OTTO', 'true', 'typ1'], true)) {
            return null;
        }

        $tables = $this->tables($bytes);

        if (! isset($tables['cmap'])) {
            return null;
        }

        $coverage = $this->coverage($bytes, $tables['cmap'][0], $tables['cmap'][1]);
        $fsType = isset($tables['OS/2']) && $tables['OS/2'][1] >= 10
            ? $this->u16($bytes, $tables['OS/2'][0] + 8)
            : null;

        return [
            'coverage' => $coverage,
            'fs_type' => $fsType,
        ];
    }

    public function missing(string $text, ?array $coverage): array
    {
        if ($coverage === null) {
            return [];
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $missing = [];

        foreach ($characters as $character) {
            if (preg_match('/^\s$/u', $character) === 1) {
                continue;
            }

            $codePoint = mb_ord($character, 'UTF-8');

            if ($codePoint === false || ! $this->covers($coverage, $codePoint)) {
                $missing[$character] = true;
            }
        }

        return array_keys($missing);
    }

    private function tables(string $bytes): array
    {
        $count = $this->u16($bytes, 4);
        $tables = [];

        for ($index = 0; $index < $count; $index++) {
            $offset = 12 + ($index * 16);

            if ($offset + 16 > strlen($bytes)) {
                break;
            }

            $tag = substr($bytes, $offset, 4);
            $tableOffset = $this->u32($bytes, $offset + 8);
            $length = $this->u32($bytes, $offset + 12);

            if ($tableOffset + $length <= strlen($bytes)) {
                $tables[$tag] = [$tableOffset, $length];
            }
        }

        return $tables;
    }

    private function coverage(string $bytes, int $offset, int $length): ?array
    {
        if ($offset + 4 > strlen($bytes) || $length < 4) {
            return null;
        }

        $count = $this->u16($bytes, $offset + 2);
        $candidates = [];

        for ($index = 0; $index < $count; $index++) {
            $record = $offset + 4 + ($index * 8);

            if ($record + 8 > strlen($bytes)) {
                break;
            }

            $platform = $this->u16($bytes, $record);
            $encoding = $this->u16($bytes, $record + 2);
            $subtable = $offset + $this->u32($bytes, $record + 4);
            $priority = match (true) {
                $platform === 3 && $encoding === 10 => 0,
                $platform === 3 && $encoding === 1 => 1,
                $platform === 0 && $encoding === 4 => 2,
                $platform === 0 && $encoding === 3 => 3,
                $platform === 0 && $encoding === 6 => 4,
                $platform === 0 => 5,
                default => null,
            };

            if ($priority !== null) {
                $candidates[$priority] = $subtable;
            }
        }

        if ($candidates === []) {
            return null;
        }

        ksort($candidates);
        $subtable = reset($candidates);
        $format = $this->u16($bytes, $subtable);

        return match ($format) {
            0 => $this->format0($bytes, $subtable),
            4 => $this->format4($bytes, $subtable),
            6 => $this->format6($bytes, $subtable),
            12 => $this->format12($bytes, $subtable),
            default => null,
        };
    }

    private function format0(string $bytes, int $offset): array
    {
        $bmp = [];

        for ($code = 0; $code < 256; $code++) {
            if ($this->u8($bytes, $offset + 6 + $code) !== 0) {
                $bmp[$code] = true;
            }
        }

        return ['bmp' => $bmp, 'ranges' => []];
    }

    private function format4(string $bytes, int $offset): array
    {
        $segments = intdiv($this->u16($bytes, $offset + 6), 2);
        $endBase = $offset + 14;
        $startBase = $endBase + ($segments * 2) + 2;
        $deltaBase = $startBase + ($segments * 2);
        $rangeBase = $deltaBase + ($segments * 2);
        $glyphBase = $rangeBase + ($segments * 2);
        $bmp = [];

        for ($index = 0; $index < $segments; $index++) {
            $end = $this->u16($bytes, $endBase + ($index * 2));
            $start = $this->u16($bytes, $startBase + ($index * 2));
            $delta = $this->s16($bytes, $deltaBase + ($index * 2));
            $rangeOffset = $this->u16($bytes, $rangeBase + ($index * 2));

            if ($start > $end) {
                continue;
            }

            for ($code = $start; $code <= $end; $code++) {
                if ($code === 0xFFFF) {
                    continue;
                }

                $glyph = 0;

                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $address = $rangeBase + ($index * 2) + $rangeOffset + (($code - $start) * 2);

                    if ($address + 1 < strlen($bytes)) {
                        $glyph = $this->u16($bytes, $address);

                        if ($glyph !== 0) {
                            $glyph = ($glyph + $delta) & 0xFFFF;
                        }
                    }
                }

                if ($glyph !== 0) {
                    $bmp[$code] = true;
                }
            }
        }

        return ['bmp' => $bmp, 'ranges' => []];
    }

    private function format6(string $bytes, int $offset): array
    {
        $first = $this->u16($bytes, $offset + 6);
        $count = $this->u16($bytes, $offset + 8);
        $bmp = [];

        for ($index = 0; $index < $count; $index++) {
            if ($this->u16($bytes, $offset + 10 + ($index * 2)) !== 0) {
                $bmp[$first + $index] = true;
            }
        }

        return ['bmp' => $bmp, 'ranges' => []];
    }

    private function format12(string $bytes, int $offset): array
    {
        $groups = $this->u32($bytes, $offset + 12);
        $ranges = [];

        for ($index = 0; $index < $groups; $index++) {
            $group = $offset + 16 + ($index * 12);

            if ($group + 12 > strlen($bytes)) {
                break;
            }

            $start = $this->u32($bytes, $group);
            $end = min(0x10FFFF, $this->u32($bytes, $group + 4));

            if ($start <= $end) {
                $ranges[] = [$start, $end];
            }
        }

        return ['bmp' => [], 'ranges' => $ranges];
    }

    private function covers(array $coverage, int $codePoint): bool
    {
        if (isset($coverage['bmp'][$codePoint])) {
            return true;
        }

        foreach ($coverage['ranges'] as [$start, $end]) {
            if ($codePoint >= $start && $codePoint <= $end) {
                return true;
            }
        }

        return false;
    }

    private function u8(string $bytes, int $offset): int
    {
        return $offset >= 0 && $offset < strlen($bytes) ? ord($bytes[$offset]) : 0;
    }

    private function u16(string $bytes, int $offset): int
    {
        return ($this->u8($bytes, $offset) << 8) | $this->u8($bytes, $offset + 1);
    }

    private function s16(string $bytes, int $offset): int
    {
        $value = $this->u16($bytes, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(string $bytes, int $offset): int
    {
        return ($this->u8($bytes, $offset) << 24)
            | ($this->u8($bytes, $offset + 1) << 16)
            | ($this->u8($bytes, $offset + 2) << 8)
            | $this->u8($bytes, $offset + 3);
    }
}
