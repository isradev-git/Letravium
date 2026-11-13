<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Card;
use App\Models\ExportManifest;
use App\Models\ImageMap;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;
use ZipArchive;

class AssetsTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function novelFor($user): Novel
    {
        $universe = $this->universeFor($user);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function fake(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    private function encodedImage(string $format): string
    {
        $image = imagecreatetruecolor(10, 10);
        imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocate($image, 10, 20, 30));

        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 85) : imagewebp($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function pixelBombPng(): string
    {
        $header = pack('NN', 100000, 100000)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header))
            .str_repeat("\0", 36);
    }

    private function docxBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>',
        );
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
        );
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_image_view_refuses_non_image_mime_from_a_restored_asset(): void
    {
        Storage::fake('local');

        $user = $this->writer();
        $novel = $this->novelFor($user);
        Storage::disk('local')->put('media/evil.html', '<script>alert(1)</script>');

        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'evil.html',
            'mime' => 'text/html',
            'bytes' => 25,
            'path' => 'media/evil.html',
            'metadata' => ['width' => 1, 'height' => 1],
        ]);

        $this->asWriter($user)
            ->get("/workspace/assets/{$asset->id}/view")
            ->assertNotFound();
    }

    public function test_styles_are_unique_and_delete_requires_a_compatible_replacement(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $base = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Cuerpo',
                'kind' => 'paragraph',
                'definition' => ['size' => 12],
            ])->assertCreated()->json('style');

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Cuerpo',
                'kind' => 'paragraph',
                'definition' => [],
            ])->assertStatus(422);

        $this->asWriter($user)
            ->patchJson("/workspace/styles/{$base['id']}", ['name' => 'Cuerpo principal'])
            ->assertOk()
            ->assertJsonPath('style.name', 'Cuerpo principal');

        $replacement = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Cita',
                'kind' => 'paragraph',
                'definition' => ['italic' => true],
            ])->assertCreated()->json('style');

        $copy = $this->asWriter($user)
            ->postJson("/workspace/styles/{$replacement['id']}/duplicate", ['name' => 'Cita copia'])
            ->assertCreated()->json('style');
        $this->assertSame($replacement['definition'], $copy['definition']);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'El puente de piedra.',
                        'style_id' => $base['id'],
                    ]],
                ],
            ])->assertCreated()->json('scene');

        $this->asWriter($user)
            ->deleteJson("/workspace/styles/{$base['id']}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'dependencies']);

        $this->asWriter($user)
            ->deleteJson("/workspace/styles/{$base['id']}", ['replacement_id' => $replacement['id']])
            ->assertOk();

        $this->assertDatabaseMissing('styles', ['id' => $base['id']]);
        $this->assertSame(
            $replacement['id'],
            Scene::findOrFail($scene['id'])->content['blocks'][0]['style_id'],
        );
    }

    public function test_deleting_a_character_style_detects_canonical_marks(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $base = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Énfasis',
                'kind' => 'character',
                'definition' => ['italic' => true],
            ])->assertCreated()->json('style');

        $replacement = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Énfasis fuerte',
                'kind' => 'character',
                'definition' => ['bold' => true],
            ])->assertCreated()->json('style');

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        $scene = $this->asWriter($user)
            ->postJson("/workspace/alternatives/{$chapter['alternatives'][0]['id']}/scenes", [
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => 'El puente de piedra.',
                        'marks' => [[
                            'type' => 'character_style',
                            'start' => 0,
                            'end' => 9,
                            'attrs' => ['id' => (string) $base['id']],
                        ]],
                    ]],
                ],
            ])->assertCreated()->json('scene');

        $this->asWriter($user)
            ->deleteJson("/workspace/styles/{$base['id']}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'dependencies']);

        $this->asWriter($user)
            ->deleteJson("/workspace/styles/{$base['id']}", ['replacement_id' => $replacement['id']])
            ->assertOk();

        $saved = Scene::findOrFail($scene['id']);
        $this->assertSame((string) $replacement['id'], $saved->content['blocks'][0]['marks'][0]['attrs']['id']);
        $this->assertSame(2, $saved->revision);
        $this->assertDatabaseHas('content_revisions', ['scene_id' => $scene['id'], 'revision' => 1]);
    }

    public function test_footnotes_number_sequentially_per_novel_and_kind(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $first = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'foot',
                'text' => 'Primera nota.',
            ])->assertCreated()->json('footnote');

        $second = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'foot',
                'text' => 'Segunda nota.',
            ])->assertCreated()->json('footnote');

        $end = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/footnotes", [
                'kind' => 'end',
                'text' => 'Nota final.',
            ])->assertCreated()->json('footnote');

        $this->assertSame([1, 2], [$first['number'], $second['number']]);
        $this->assertSame(1, $end['number']);

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/footnotes")
            ->assertOk()
            ->assertJsonCount(3, 'footnotes');
    }

    public function test_images_are_really_validated_stored_privately_and_only_downloaded_by_owner(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $asset = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('foto.png', $this->pngBytes()),
                'novel_id' => $novel->id,
            ])->assertCreated()->json('asset');

        $this->assertSame('image/png', $asset['mime']);
        $this->assertSame(1, $asset['metadata']['width']);
        $this->assertSame(1, $asset['metadata']['height']);

        $stored = MediaAsset::findOrFail($asset['id']);
        Storage::disk('local')->assertExists($stored->path);
        Storage::disk('public')->assertMissing($stored->path);

        $this->asWriter($user)
            ->get("/workspace/assets/{$asset['id']}/download")
            ->assertOk();

        $this->asWriter($this->writer())
            ->get("/workspace/assets/{$asset['id']}/download")
            ->assertNotFound();

        $this->asWriter($user)
            ->get("/workspace/assets/{$asset['id']}/view")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->asWriter($this->writer())
            ->get("/workspace/assets/{$asset['id']}/view")
            ->assertNotFound();

        $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('falsa.png', 'MZ'.str_repeat("\x00", 64)),
            ])->assertStatus(422);

        $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('grande.png', $this->pngBytes().str_repeat('A', 10 * 1024 * 1024)),
            ])->assertStatus(422);
    }

    public function test_image_declarations_and_truncated_payloads_are_rejected_without_artifacts(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('bomba.png', $this->pixelBombPng()),
                'novel_id' => $novel->id,
            ])->assertStatus(422);

        foreach ([
            'truncada.png' => substr($this->pngBytes(), 0, (int) (strlen($this->pngBytes()) * 0.6)),
            'truncada.jpg' => substr($this->encodedImage('jpeg'), 0, 200),
            'truncada.webp' => substr($this->encodedImage('webp'), 0, 20),
            'sin-eoi.jpg' => substr($this->encodedImage('jpeg'), 0, -2),
            'sin-iend.png' => substr($this->pngBytes(), 0, -12),
            'sin-riff.webp' => substr($this->encodedImage('webp'), 0, -2),
        ] as $name => $bytes) {
            $this->asWriter($user)
                ->post('/workspace/assets', [
                    'kind' => 'image',
                    'file' => $this->fake($name, $bytes),
                    'novel_id' => $novel->id,
                ])->assertStatus(422);
        }

        $this->assertSame(0, MediaAsset::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_valid_jpeg_and_webp_images_are_accepted(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        foreach (['foto.jpg' => ['jpeg', 'image/jpeg'], 'foto.webp' => ['webp', 'image/webp']] as $name => [$format, $mime]) {
            $asset = $this->asWriter($user)
                ->post('/workspace/assets', [
                    'kind' => 'image',
                    'file' => $this->fake($name, $this->encodedImage($format)),
                    'novel_id' => $novel->id,
                ])->assertCreated()->json('asset');

            $this->assertSame($mime, $asset['mime']);
            Storage::disk('local')->assertExists(MediaAsset::findOrFail($asset['id'])->path);
        }
    }

    public function test_attachments_accept_pdf_docx_and_txt_but_reject_active_or_fake_files(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();

        $pdf = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'attachment',
                'file' => $this->fake('investigacion.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"),
            ])->assertCreated()->json('asset');
        $this->assertSame('application/pdf', $pdf['mime']);

        $docx = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'attachment',
                'file' => $this->fake('notas.docx', $this->docxBytes()),
            ])->assertCreated()->json('asset');
        $this->assertStringContainsString('wordprocessingml', $docx['mime']);

        $txt = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'attachment',
                'file' => $this->fake('apuntes.txt', "Notas de investigación.\n"),
            ])->assertCreated()->json('asset');
        $this->assertSame('text/plain', $txt['mime']);

        $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'attachment',
                'file' => $this->fake('malicioso.pdf', 'MZ'.str_repeat("\x00", 64)),
            ])->assertStatus(422);

        $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'attachment',
                'file' => $this->fake('activo.pdf', "%PDF-1.4\n/OpenAction <<>>\n%%EOF"),
            ])->assertStatus(422);
    }

    public function test_asset_deletion_reports_map_dependencies_instead_of_breaking_them(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $asset = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('mapa.png', $this->pngBytes()),
                'universe_id' => $universe->id,
            ])->assertCreated()->json('asset');

        $place = Card::create([
            'universe_id' => $universe->id,
            'area' => 'places',
            'name' => 'Puerto',
        ]);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset['id'],
                'markers' => [['place_card_id' => $place->id, 'x' => 0.25, 'y' => 0.5]],
            ])->assertCreated();

        $this->asWriter($user)
            ->deleteJson("/workspace/assets/{$asset['id']}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'dependencies']);

        $this->assertDatabaseHas('media_assets', ['id' => $asset['id']]);
    }

    public function test_asset_deletion_reports_snapshot_manifest_and_cover_dependencies(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);

        $asset = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('mapa.png', $this->pngBytes()),
                'novel_id' => $novel->id,
            ])->assertCreated()->json('asset');

        Snapshot::create([
            'novel_id' => $novel->id,
            'name' => 'Versión con mapa',
            'manifest' => ['resources' => ['media' => [['id' => $asset['id'], 'exists' => true]]]],
            'created_by' => $user->id,
        ]);

        ExportManifest::create([
            'novel_id' => $novel->id,
            'formats' => ['pdf'],
            'manifest' => ['resources' => ['media' => [['id' => $asset['id'], 'exists' => true]]]],
            'created_by' => $user->id,
        ]);

        $profile = BookProfile::create([
            'novel_id' => $novel->id,
            'name' => 'Rústica',
            'cover' => ['image_asset_id' => $asset['id']],
        ]);

        $this->asWriter($user)
            ->deleteJson("/workspace/assets/{$asset['id']}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'dependencies']);

        $this->assertDatabaseHas('media_assets', ['id' => $asset['id']]);
        $this->assertNotNull($profile->refresh());
    }

    public function test_fonts_require_real_ttf_or_otf_bytes_and_report_style_dependencies(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();

        $font = $this->asWriter($user)
            ->post('/workspace/fonts', [
                'family' => 'Literata',
                'variant' => 'regular',
                'license' => 'OFL',
                'file' => $this->fake('literata.ttf', "\x00\x01\x00\x00".str_repeat("\x00", 60)),
            ])->assertCreated()->json('font');

        $this->asWriter($user)
            ->post('/workspace/fonts', [
                'family' => 'Falsa',
                'variant' => 'regular',
                'file' => $this->fake('falsa.ttf', $this->pngBytes()),
            ])->assertStatus(422);

        $unused = $this->asWriter($user)
            ->post('/workspace/fonts', [
                'family' => 'Literata',
                'variant' => 'italic',
                'file' => $this->fake('literata-italic.otf', 'OTTO'.str_repeat("\x00", 60)),
            ])->assertCreated()->json('font');

        $this->asWriter($user)
            ->deleteJson("/workspace/fonts/{$unused['id']}")
            ->assertOk();

        $novel = $this->novelFor($user);
        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/styles", [
                'name' => 'Cuerpo',
                'kind' => 'paragraph',
                'definition' => ['font_id' => $font['id'], 'size' => 12],
            ])->assertCreated();

        $this->asWriter($user)
            ->deleteJson("/workspace/fonts/{$font['id']}")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'dependencies']);

        $this->assertDatabaseHas('fonts', ['id' => $font['id']]);
    }

    public function test_image_maps_link_place_cards_from_the_same_universe(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);

        $asset = $this->asWriter($user)
            ->post('/workspace/assets', [
                'kind' => 'image',
                'file' => $this->fake('mapa.png', $this->pngBytes()),
                'universe_id' => $universe->id,
            ])->assertCreated()->json('asset');

        $place = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Puerto']);
        $otherPlace = Card::create([
            'universe_id' => $this->universeFor($this->writer())->id,
            'area' => 'places',
            'name' => 'Ajeno',
        ]);

        $map = $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset['id'],
                'markers' => [[
                    'place_card_id' => $place->id,
                    'x' => 0.25,
                    'y' => 0.5,
                    'label' => 'Puerto',
                    'route_note' => 'Tres días a caballo.',
                ]],
            ])->assertCreated()
            ->assertJsonPath('image_map.markers.0.place_card_id', $place->id)
            ->json('image_map');

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/image-maps")
            ->assertOk()
            ->assertJsonCount(1, 'image_maps');

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset['id'],
                'markers' => [['place_card_id' => $otherPlace->id, 'x' => 0.1, 'y' => 0.1]],
            ])->assertStatus(422);

        $this->asWriter($user)
            ->postJson("/workspace/universes/{$universe->id}/image-maps", [
                'image_asset_id' => $asset['id'],
                'markers' => [['place_card_id' => $place->id, 'x' => 1.2, 'y' => 0.1]],
            ])->assertStatus(422);

        $this->asWriter($user)
            ->patchJson("/workspace/image-maps/{$map['id']}", [
                'markers' => [['place_card_id' => $place->id, 'x' => 0.6, 'y' => 0.4]],
            ])->assertOk()
            ->assertJsonPath('image_map.markers.0.x', 0.6);

        $secondPlace = Card::create([
            'universe_id' => $universe->id,
            'area' => 'places',
            'name' => 'Faro',
        ]);

        $this->asWriter($user)
            ->postJson("/workspace/image-maps/{$map['id']}/markers", [
                'place_card_id' => $secondPlace->id,
                'x' => 0.8,
                'y' => 0.2,
            ])->assertCreated()
            ->assertJsonCount(2, 'image_map.markers');

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/image-maps", [
                'image_asset_id' => $asset['id'],
                'markers' => [],
            ])->assertCreated();

        $this->assertSame(2, ImageMap::where('universe_id', $universe->id)->count());
    }
}
