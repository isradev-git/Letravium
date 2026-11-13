<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;
use ZipArchive;

class ExportMetadataTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private const MARKER = 'PRIVATE-GPS-MARKER';

    private function imageWithMetadata(string $format): string
    {
        $image = imagecreatetruecolor(20, 10);
        imagefilledrectangle($image, 0, 0, 19, 9, imagecolorallocate($image, 10, 20, 30));

        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 85) : ($format === 'png' ? imagepng($image) : imagewebp($image));
        $bytes = (string) ob_get_clean();

        if ($format === 'jpeg') {
            $exif = "Exif\0\0".self::MARKER.str_repeat("\0", 8);
            $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
            $app2Data = "XMP\0".self::MARKER;
            $app2 = "\xFF\xE2".pack('n', strlen($app2Data) + 2).$app2Data;

            return substr($bytes, 0, 2).$segment.$app2.substr($bytes, 2);
        }

        if ($format === 'png') {
            $data = "Comment\0".self::MARKER;
            $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));

            return substr($bytes, 0, -12).$chunk.substr($bytes, -12);
        }

        $data = self::MARKER;
        $chunk = 'EXIF'.pack('V', strlen($data)).$data;

        if (strlen($data) % 2 === 1) {
            $chunk .= "\0";
        }

        $bytes .= $chunk;

        return substr($bytes, 0, 4).pack('V', strlen($bytes) - 8).substr($bytes, 8);
    }

    private function asset(Novel $novel, string $format, string $extension, string $mime): MediaAsset
    {
        $bytes = $this->imageWithMetadata($format);
        $path = "media/{$format}.{$extension}";
        Storage::disk('local')->put($path, $bytes);

        return MediaAsset::create([
            'owner_id' => $novel->owner_id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => "figura.{$extension}",
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'path' => $path,
            'metadata' => ['width' => 20, 'height' => 10],
        ]);
    }

    private function sceneWithAssets(Novel $novel, array $assets): void
    {
        $blocks = [];

        foreach ($assets as $index => $asset) {
            $blocks[] = ['id' => 'img'.$index, 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Figura']];
        }

        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => $blocks],
        ]);
    }

    private function exportedMedia(string $archive, string $prefix): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($path, $archive);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $media = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, $prefix)) {
                $media[$name] = (string) $zip->getFromName($name);
            }
        }

        $zip->close();
        unlink($path);

        return $media;
    }

    public function test_docx_epub_and_pdf_resources_strip_jpeg_and_png_metadata(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        BookProfile::create(['novel_id' => $novel->id, 'name' => 'A5']);

        $assets = [
            $this->asset($novel, 'jpeg', 'jpg', 'image/jpeg'),
            $this->asset($novel, 'png', 'png', 'image/png'),
        ];
        $this->sceneWithAssets($novel, $assets);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx', 'epub', 'pdf']])
            ->assertCreated()
            ->json('manifest');

        $docx = $this->asWriter($user)->get("/workspace/export-manifests/{$manifest['id']}/docx")->assertOk()->getContent();
        $epub = $this->asWriter($user)->get("/workspace/export-manifests/{$manifest['id']}/epub")->assertOk()->getContent();

        foreach ([
            'docx' => $this->exportedMedia($docx, 'word/media/'),
            'epub' => $this->exportedMedia($epub, 'OEBPS/media/'),
        ] as $label => $media) {
            $this->assertCount(2, $media, "{$label} must embed both images");

            foreach ($media as $name => $bytes) {
                $this->assertStringNotContainsString(self::MARKER, $bytes, "{$label} {$name} leaked private metadata");
                $size = @getimagesizefromstring($bytes);
                $this->assertSame([20, 10], [$size[0] ?? 0, $size[1] ?? 0]);
            }
        }

        foreach ($assets as $asset) {
            $response = $this->asWriter($user)
                ->get("/workspace/export-manifests/{$manifest['id']}/pdf-resource/media/{$asset->id}")
                ->assertOk();
            $bytes = $response->getContent();
            $this->assertStringNotContainsString(self::MARKER, $bytes);
            $this->assertNotFalse(@imagecreatefromstring($bytes));
            $this->assertSame(20, @getimagesizefromstring($bytes)[0]);
            $this->assertStringContainsString(self::MARKER, Storage::disk('local')->get($asset->path));
        }
    }

    public function test_webp_images_are_converted_for_docx_epub_and_pdf(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        BookProfile::create(['novel_id' => $novel->id, 'name' => 'A5']);

        $asset = $this->asset($novel, 'webp', 'webp', 'image/webp');
        $this->sceneWithAssets($novel, [$asset]);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx', 'epub', 'pdf']])
            ->assertCreated()
            ->json('manifest');

        $docx = $this->asWriter($user)->get("/workspace/export-manifests/{$manifest['id']}/docx")->assertOk()->getContent();
        $epub = $this->asWriter($user)->get("/workspace/export-manifests/{$manifest['id']}/epub")->assertOk()->getContent();

        foreach ([
            'docx' => $this->exportedMedia($docx, 'word/media/'),
            'epub' => $this->exportedMedia($epub, 'OEBPS/media/'),
        ] as $label => $media) {
            $this->assertCount(1, $media, "{$label} must embed the converted image");
            $bytes = reset($media);
            $this->assertStringNotContainsString(self::MARKER, $bytes);
            $size = @getimagesizefromstring($bytes);
            $this->assertSame([20, 10], [$size[0] ?? 0, $size[1] ?? 0]);
        }

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/pdf-resource/media/{$asset->id}")
            ->assertOk();
        $bytes = $response->getContent();
        $this->assertStringNotContainsString(self::MARKER, $bytes);
        $this->assertSame(20, @getimagesizefromstring($bytes)[0]);
        $this->assertStringContainsString(self::MARKER, Storage::disk('local')->get($asset->path));
    }
}
