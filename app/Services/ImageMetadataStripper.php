<?php

namespace App\Services;

class ImageMetadataStripper
{
    public function prepare(string $bytes, string $mime): array
    {
        return match ($mime) {
            'image/jpeg' => ['bytes' => $this->stripJpeg($bytes), 'mime' => 'image/jpeg', 'extension' => 'jpg'],
            'image/png' => ['bytes' => $this->stripPng($bytes), 'mime' => 'image/png', 'extension' => 'png'],
            'image/webp' => $this->webpAsPng($bytes),
            default => ['bytes' => $bytes, 'mime' => $mime, 'extension' => null],
        };
    }

    private function stripJpeg(string $bytes): string
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return $bytes;
        }

        $segments = '';
        $orientation = null;
        $sawScan = false;
        $length = strlen($bytes);
        $offset = 2;

        while ($offset + 1 < $length) {
            if ($bytes[$offset] !== "\xFF") {
                break;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xD9) {
                $offset += 2;

                break;
            }

            if ($marker === 0xDA) {
                $segments .= substr($bytes, $offset);
                $sawScan = true;

                break;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $segments .= substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }

            if ($offset + 3 >= $length) {
                break;
            }

            $segmentLength = unpack('n', substr($bytes, $offset + 2, 2))[1];

            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                break;
            }

            $payload = substr($bytes, $offset + 4, $segmentLength - 2);
            $keep = $marker !== 0xFE && ! ($marker >= 0xE0 && $marker <= 0xEF);

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation ??= $this->jpegOrientation($payload);
            } elseif ($marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0")) {
                $keep = true;
            } elseif ($marker === 0xEE) {
                $keep = true;
            }

            if ($keep) {
                $segments .= substr($bytes, $offset, 2 + $segmentLength);
            }

            $offset += 2 + $segmentLength;
        }

        if (! $sawScan) {
            return $this->reencodeJpeg($bytes);
        }

        $out = "\xFF\xD8";

        if ($orientation !== null && $orientation >= 2 && $orientation <= 8) {
            $exif = $this->orientationExif($orientation);
            $out .= "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        }

        $out .= $segments;

        return str_ends_with($out, "\xFF\xD9") ? $out : $this->reencodeJpeg($bytes);
    }

    private function jpegOrientation(string $payload): ?int
    {
        $tiff = substr($payload, 6);

        if (strlen($tiff) < 8) {
            return null;
        }

        $endian = substr($tiff, 0, 2);
        $short = match ($endian) {
            'II' => 'v',
            'MM' => 'n',
            default => null,
        };

        if ($short === null || unpack($short, substr($tiff, 2, 2))[1] !== 42) {
            return null;
        }

        $ifdOffset = unpack($endian === 'II' ? 'V' : 'N', substr($tiff, 4, 4))[1];

        if ($ifdOffset + 2 > strlen($tiff)) {
            return null;
        }

        $entries = unpack($short, substr($tiff, $ifdOffset, 2))[1];

        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifdOffset + 2 + $index * 12;

            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            $tag = unpack($short, substr($tiff, $entry, 2))[1];

            if ($tag !== 0x0112) {
                continue;
            }

            $type = unpack($short, substr($tiff, $entry + 2, 2))[1];
            $count = unpack($endian === 'II' ? 'V' : 'N', substr($tiff, $entry + 4, 4))[1];

            if ($type !== 3 || $count < 1) {
                return null;
            }

            $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

            return $value >= 1 && $value <= 8 ? $value : null;
        }

        return null;
    }

    private function orientationExif(int $orientation): string
    {
        $tiff = "MM\0\x2A\0\0\0\x08"
            ."\x00\x01"
            ."\x01\x12\x00\x03\x00\x00\x00\x01"
            .pack('n', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";

        return "Exif\0\0".$tiff;
    }

    private function reencodeJpeg(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return $bytes;
        }

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }

    private function stripPng(string $bytes): string
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $bytes;
        }

        $out = substr($bytes, 0, 8);
        $length = strlen($bytes);
        $offset = 8;

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);

            if ($offset + 12 + $size > $length) {
                return $bytes;
            }

            if (! in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                $out .= substr($bytes, $offset, 12 + $size);
            }

            $offset += 12 + $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return $bytes;
    }

    private function webpAsPng(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return ['bytes' => $bytes, 'mime' => 'image/webp', 'extension' => 'webp'];
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return ['bytes' => $png, 'mime' => 'image/png', 'extension' => 'png'];
    }
}
