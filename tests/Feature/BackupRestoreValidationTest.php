<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;
use ZipArchive;

class BackupRestoreValidationTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function package(string $bytes, string $mime, string $kind = 'image'): string
    {
        $resource = 'resources/media/1/evil.png';
        $content = [
            'universe' => ['name' => 'Arven', 'description' => null],
            'cards' => [],
            'card_relationships' => [],
            'novels' => [],
            'parts' => [],
            'work_states' => [],
            'chapters' => [],
            'chapter_alternatives' => [],
            'scenes' => [],
            'plots' => [],
            'plot_scene' => [],
            'styles' => [],
            'footnotes' => [],
            'text_references' => [],
            'image_maps' => [],
            'media_assets' => [[
                'id' => 1,
                'universe_id' => null,
                'novel_id' => null,
                'kind' => $kind,
                'original_name' => 'evil.png',
                'mime' => $mime,
                'bytes' => strlen($bytes),
                'metadata' => [],
                'resource' => $resource,
            ]],
            'fonts' => [],
            'novel_goals' => [],
        ];

        $contentJson = (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $files = [
            'content.json' => ['sha256' => hash('sha256', $contentJson), 'bytes' => strlen($contentJson)],
            $resource => ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)],
        ];

        $manifest = [
            'format' => 'escritor-universe-backup',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'universe' => ['name' => 'Arven', 'description' => null],
            'counts' => ['novels' => 0, 'cards' => 0, 'scenes' => 0, 'resources' => 1],
            'files' => $files,
        ];

        $path = tempnam(sys_get_temp_dir(), 'backup').'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('content.json', $contentJson);
        $zip->addFromString($resource, $bytes);
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    public function test_restore_rejects_an_image_asset_with_non_image_bytes(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $path = $this->package('<script>alert(1)</script>', 'image/png');

        $this->asWriter($user)->post('/workspace/backups/restore', [
            'file' => new UploadedFile($path, 'copia.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('universes', 0);
    }
}
