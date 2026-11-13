<?php

namespace Tests\Unit;

use App\Services\ImageMetadataStripper;
use PHPUnit\Framework\TestCase;

class ImageMetadataStripperTest extends TestCase
{
    private const MARKER = 'PRIVATE-LOCATION-EXAMPLE';

    private function jpegWithMetadata(): string
    {
        $image = imagecreatetruecolor(4, 3);
        imagefilledrectangle($image, 0, 0, 3, 2, imagecolorallocate($image, 12, 34, 56));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();

        $tiff = "MM\0\x2A\0\0\0\x08"
            ."\x00\x01"
            ."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"
            ."\x00\x00\x00\x00"
            .self::MARKER;
        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        $app2Data = "XMP\0".self::MARKER;
        $app2 = "\xFF\xE2".pack('n', strlen($app2Data) + 2).$app2Data;

        $icc = "ICC_PROFILE\0".str_repeat('A', 16);
        $iccApp2 = "\xFF\xE2".pack('n', strlen($icc) + 2).$icc;

        return substr($bytes, 0, 2).$app1.$app2.$iccApp2.substr($bytes, 2);
    }

    public function test_jpeg_strips_app2_and_private_segments_but_keeps_orientation_and_icc(): void
    {
        $original = $this->jpegWithMetadata();
        $prepared = (new ImageMetadataStripper)->prepare($original, 'image/jpeg');

        $this->assertSame('image/jpeg', $prepared['mime']);
        $this->assertStringNotContainsString(self::MARKER, $prepared['bytes']);
        $this->assertStringContainsString("ICC_PROFILE\0", $prepared['bytes']);
        $this->assertStringContainsString("\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06", $prepared['bytes']);
        $this->assertStringContainsString(self::MARKER, $original);

        $size = @getimagesizefromstring($prepared['bytes']);
        $this->assertSame([4, 3], [$size[0] ?? 0, $size[1] ?? 0]);
        $this->assertStringEndsWith("\xFF\xD9", $prepared['bytes']);
    }
}
