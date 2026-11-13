<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\ExportManifest;
use App\Models\Font;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Style;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ExportAuthorizationTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user, string $title = 'Propia'): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function sceneFor(Novel $novel, string $text = 'Hola'): Scene
    {
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => 'Uno',
            'position' => 0,
        ]);

        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);

        return Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => $text]],
            ],
        ]);
    }

    private function imageDocument(int $assetId): array
    {
        return [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Hola'],
                ['id' => 'i1', 'type' => 'image', 'attrs' => ['asset_id' => $assetId, 'alt' => 'x']],
            ],
        ];
    }

    private function victimImage($victim): MediaAsset
    {
        $secret = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('local')->put('media/victim/secret.png', $secret);

        return MediaAsset::create([
            'owner_id' => $victim->id,
            'kind' => 'image',
            'original_name' => 'victima.png',
            'mime' => 'image/png',
            'bytes' => strlen($secret),
            'path' => 'media/victim/secret.png',
            'metadata' => ['width' => 1, 'height' => 1],
        ]);
    }

    public function test_saving_a_foreign_image_into_own_scene_is_rejected(): void
    {
        Storage::fake('local');
        $victim = $this->writer();
        $attacker = $this->writer();
        $asset = $this->victimImage($victim);
        $scene = $this->sceneFor($this->novelFor($attacker));

        $this->asWriter($attacker)
            ->putJson("/workspace/scenes/{$scene->id}/content", [
                'base_revision' => 1,
                'content' => $this->imageDocument($asset->id),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_export_manifest_cannot_serve_a_foreign_image(): void
    {
        Storage::fake('local');
        $victim = $this->writer();
        $attacker = $this->writer();
        $asset = $this->victimImage($victim);
        $novel = $this->novelFor($attacker);
        $scene = $this->sceneFor($novel);

        $scene->forceFill(['content' => $this->imageDocument($asset->id)])->save();

        $this->asWriter($attacker)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['txt']])
            ->assertStatus(422);

        $manifest = ExportManifest::create([
            'novel_id' => $novel->id,
            'formats' => ['txt'],
            'manifest' => [
                'resources' => [
                    'fonts' => [],
                    'media' => [[
                        'id' => $asset->id,
                        'path' => $asset->path,
                        'mime' => $asset->mime,
                        'exists' => true,
                    ]],
                ],
            ],
            'created_by' => $attacker->id,
            'created_at' => now(),
        ]);

        $this->asWriter($attacker)
            ->get("/workspace/export-manifests/{$manifest->id}/pdf-resource/media/{$asset->id}")
            ->assertNotFound();
    }

    public function test_export_manifest_cannot_serve_a_foreign_font(): void
    {
        Storage::fake('local');
        $victim = $this->writer();
        $attacker = $this->writer();
        Storage::disk('local')->put('fonts/victim/secret.ttf', "\x00\x01\x00\x00victim-font");
        $font = Font::create([
            'owner_id' => $victim->id,
            'family' => 'Victima',
            'variant' => 'regular',
            'path' => 'fonts/victim/secret.ttf',
            'bytes' => 18,
            'license' => 'SIL OFL',
        ]);

        $novel = $this->novelFor($attacker);
        $this->sceneFor($novel);
        Style::create([
            'novel_id' => $novel->id,
            'name' => 'Cuerpo',
            'kind' => 'paragraph',
            'definition' => ['body_font_id' => $font->id],
        ]);

        $this->asWriter($attacker)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['txt']])
            ->assertStatus(422);

        $manifest = ExportManifest::create([
            'novel_id' => $novel->id,
            'formats' => ['txt'],
            'manifest' => [
                'resources' => [
                    'fonts' => [[
                        'id' => $font->id,
                        'path' => $font->path,
                        'exists' => true,
                    ]],
                    'media' => [],
                ],
            ],
            'created_by' => $attacker->id,
            'created_at' => now(),
        ]);

        $this->asWriter($attacker)
            ->get("/workspace/export-manifests/{$manifest->id}/pdf-resource/font/{$font->id}")
            ->assertNotFound();
    }
}
