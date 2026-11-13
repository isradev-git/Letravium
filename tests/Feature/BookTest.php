<?php

namespace Tests\Feature;

use App\Models\BookMark;
use App\Models\BookMetadata;
use App\Models\BookProfile;
use App\Models\BookSection;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\ExportManifest;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;
use ZipArchive;

class BookTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user, string $title = 'La carta'): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function chapterWithScene(Novel $novel, string $title, string $text, array $extraBlocks = []): array
    {
        $chapter = Chapter::create([
            'novel_id' => $novel->id,
            'title' => $title,
            'position' => (int) $novel->chapters()->max('position') + 1,
        ]);

        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);

        $blocks = array_merge([
            ['id' => 'h-'.$chapter->id, 'type' => 'heading', 'level' => 2, 'text' => 'Primera parte'],
            ['id' => 'p-'.$chapter->id, 'type' => 'paragraph', 'text' => $text],
        ], $extraBlocks);

        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Escena',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => $blocks],
        ]);

        return [$chapter, $alternative, $scene];
    }

    private function profileFor(Novel $novel, array $overrides = []): BookProfile
    {
        return BookProfile::create(array_merge([
            'novel_id' => $novel->id,
            'name' => 'Rústica 148x210',
            'page_size' => '148x210mm',
            'orientation' => 'portrait',
            'margins' => ['top' => 18, 'bottom' => 22, 'left' => 16, 'right' => 16],
            'binding' => 'rústica cosida',
            'facing_pages' => true,
            'right_page_starts' => true,
            'widows' => 3,
            'orphans' => 3,
            'keep_with_next' => true,
        ], $overrides));
    }

    public function test_book_profile_crud_keeps_cover_and_spread_dimensions_as_provided(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $cover = [
            'trim_mm' => ['width' => 148, 'height' => 210],
            'spread_mm' => ['width' => 306, 'height' => 216],
            'bleed_mm' => 3,
            'spine_width_mm' => null,
            'spine_source' => 'Datos de papel y encuadernación aportados por la imprenta',
            'front' => ['text' => 'Título'],
            'back' => ['text' => 'Sinopsis'],
            'spine' => ['text' => 'Autor'],
        ];

        $profile = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/book/profiles", [
                'name' => 'Rústica 148x210',
                'page_size' => '148x210mm',
                'orientation' => 'portrait',
                'margins' => ['top' => 18, 'bottom' => 22, 'left' => 16, 'right' => 16],
                'binding' => 'rústica cosida',
                'fonts' => ['body_font_id' => 7],
                'chapter_policy' => ['start_on_right' => true, 'separate_pages' => true],
                'notes_policy' => ['kind' => 'foot', 'numbering' => 'per-chapter'],
                'facing_pages' => true,
                'right_page_starts' => true,
                'running_heads' => ['odd' => 'chapter', 'even' => 'book'],
                'widows' => 3,
                'orphans' => 3,
                'keep_with_next' => true,
                'cover' => $cover,
                'compositor_limits' => ['max_widows' => 3, 'max_orphans' => 3],
            ])->assertCreated()->json('profile');

        $this->assertSame('148x210mm', $profile['page_size']);
        $this->assertEquals($cover, $profile['cover']);
        $this->assertNull($profile['cover']['spine_width_mm'], 'La anchura del lomo nunca se inventa.');
        $this->assertTrue($profile['facing_pages']);
        $this->assertSame(3, $profile['widows']);

        $this->asWriter($user)
            ->patchJson("/workspace/book/profiles/{$profile['id']}", [
                'name' => 'Rústica 148x210 (revisada)',
                'margins' => ['top' => 20, 'bottom' => 24, 'left' => 15, 'right' => 15],
            ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'Rústica 148x210 (revisada)')
            ->assertJsonPath('profile.margins.left', 15);

        $copy = $this->asWriter($user)
            ->postJson("/workspace/book/profiles/{$profile['id']}/duplicate", ['name' => 'Plantilla reutilizable'])
            ->assertCreated()
            ->json('profile');

        $this->assertEquals($profile['cover'], $copy['cover']);
        $this->assertSame(2, count($this->asWriter($user)->getJson("/workspace/novels/{$novel->id}/book/profiles")->json('profiles')));

        $this->asWriter($user)->deleteJson("/workspace/book/profiles/{$copy['id']}")->assertOk();
        $this->asWriter($this->writer())->getJson("/workspace/novels/{$novel->id}/book/profiles")->assertNotFound();
    }

    public function test_book_setup_rejects_null_values_for_non_nullable_columns(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $profile = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/book/profiles", ['name' => 'Rústica'])
            ->assertCreated()
            ->json('profile');

        $this->asWriter($user)
            ->patchJson("/workspace/book/profiles/{$profile['id']}", [
                'page_size' => null,
                'orientation' => null,
                'facing_pages' => null,
                'widows' => null,
            ])->assertStatus(422);

        $this->asWriter($user)
            ->putJson("/workspace/novels/{$novel->id}/book/metadata", ['language' => null])
            ->assertStatus(422);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/book/sections", [
                'area' => 'front',
                'type' => 'credits',
                'include_in_export' => null,
                'position' => null,
            ])->assertStatus(422);
    }

    public function test_front_back_matter_metadata_and_navigation_use_explicit_marks_only(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->putJson("/workspace/novels/{$novel->id}/book/metadata", [
                'title' => 'La carta',
                'subtitle' => 'Una novela de invierno',
                'author' => 'Ana de Prueba',
                'language' => 'es',
                'rights' => 'Todos los derechos reservados',
                'edition' => 'Primera',
                'publisher' => 'Editorial Sintética',
                'isbn' => '978-00-0000000-0-0',
            ])->assertOk()->assertJsonPath('metadata.isbn', '978-00-0000000-0-0');

        foreach ([
            ['area' => 'front', 'type' => 'dedication', 'title' => 'Dedicatoria', 'content' => 'Para quienes leen.'],
            ['area' => 'back', 'type' => 'glossary', 'title' => 'Glosario', 'content' => 'Términos elegidos.'],
        ] as $section) {
            $this->asWriter($user)
                ->postJson("/workspace/novels/{$novel->id}/book/sections", $section)
                ->assertCreated();
        }

        [$chapter, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto visible.');
        $chapter->forceFill(['plan' => ['private' => 'PLAN PRIVADO']])->save();

        $inactive = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Descartada',
            'is_active' => false,
        ]);
        Scene::create([
            'chapter_alternative_id' => $inactive->id,
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'x', 'type' => 'paragraph', 'text' => 'SECRETO INACTIVO']],
            ],
        ]);

        foreach ([
            ['type' => 'figure', 'label' => 'Figura 1', 'caption' => 'Mapa del invierno'],
            ['type' => 'table', 'label' => 'Tabla 1', 'caption' => 'Cronología'],
            ['type' => 'index', 'label' => 'Zeta', 'target_type' => 'chapter', 'target_id' => $chapter->id],
            ['type' => 'index', 'label' => 'Árbol', 'target_type' => 'scene', 'target_id' => $scene->id],
            ['type' => 'glossary', 'label' => 'Bruma', 'caption' => 'Niebla baja.'],
        ] as $mark) {
            $this->asWriter($user)
                ->postJson("/workspace/novels/{$novel->id}/book/marks", $mark)
                ->assertCreated();
        }

        $navigation = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/book/navigation")
            ->assertOk()
            ->json('navigation');

        $this->assertSame('Uno', $navigation['toc'][0]['title']);
        $this->assertSame('Primera parte', $navigation['toc'][0]['children'][0]['title']);
        $this->assertCount(1, $navigation['figures']);
        $this->assertCount(1, $navigation['tables']);
        $this->assertSame('Árbol', $navigation['index'][0]['label']);
        $this->assertSame('A', $navigation['index'][0]['letter']);
        $this->assertSame('Bruma', $navigation['glossary'][0]['label']);

        $response = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/book/navigation")
            ->assertOk();

        $this->assertStringNotContainsString('PLAN PRIVADO', $response->getContent());
        $this->assertStringNotContainsString('SECRETO INACTIVO', $response->getContent());
        $this->assertDatabaseHas('book_metadata', ['novel_id' => $novel->id, 'author' => 'Ana de Prueba']);
    }

    public function test_preflight_separates_blocking_errors_from_warnings_and_blocks_manifest_creation(): void
    {
        Storage::fake('local');

        $user = $this->writer();
        $novel = $this->novelFor($user);

        $fontPath = 'fonts/prueba.ttf';
        Storage::disk('local')->put($fontPath, 'fuente');
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Prueba',
            'variant' => 'Regular',
            'path' => $fontPath,
            'bytes' => 6,
            'license' => null,
            'metadata' => ['format' => 'ttf'],
        ]);

        $assetPath = 'media/prueba.png';
        Storage::disk('local')->put($assetPath, 'imagen');
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'prueba.png',
            'mime' => 'image/png',
            'bytes' => 6,
            'path' => $assetPath,
            'metadata' => ['width' => 1, 'height' => 1],
        ]);

        $this->profileFor($novel, ['fonts' => ['body_font_id' => $font->id]]);

        [$chapter] = $this->chapterWithScene($novel, 'Con imagen', 'Texto breve.', [
            ['id' => 'img', 'type' => 'paragraph', 'text' => 'Imagen', 'image_asset_id' => $asset->id],
            ['id' => 'roto', 'type' => 'paragraph', 'text' => 'Referencia rota', 'image_asset_id' => 999999],
        ]);

        $inactive = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'No exportable',
            'is_active' => false,
        ]);
        $inactiveScene = Scene::create([
            'chapter_alternative_id' => $inactive->id,
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'x', 'type' => 'paragraph', 'text' => 'Escena inactiva.']],
            ],
        ]);

        Chapter::create(['novel_id' => $novel->id, 'title' => 'Vacío', 'position' => 99]);

        Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => null,
            'kind' => 'foot',
            'number' => 1,
            'text' => '',
        ]);

        Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $inactiveScene->id,
            'kind' => 'foot',
            'number' => 2,
            'text' => 'Nota con ancla inexistente.',
        ]);

        $result = $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/book/preflight")
            ->assertOk()
            ->json('preflight');

        $this->assertFalse($result['can_export']);

        $blocking = array_column($result['blocking'], 'code');
        $warnings = array_column($result['warnings'], 'code');

        $this->assertContains('note.incomplete', $blocking);
        $this->assertContains('note.broken_reference', $blocking);
        $this->assertContains('resource.broken_reference', $blocking);
        $this->assertContains('font.restricted', $warnings);
        $this->assertContains('image.low_resolution', $warnings);
        $this->assertContains('chapter.empty', $warnings);
        $this->assertContains('note.unanchored', $warnings);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['txt']])
            ->assertStatus(422)
            ->assertJsonPath('preflight.can_export', false);
    }

    public function test_export_manifest_is_immutable_and_txt_uses_the_frozen_snapshot(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'front',
            'type' => 'prologue',
            'title' => 'Prólogo',
            'content' => 'Texto preliminar.',
            'position' => 0,
        ]);

        [$chapter, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto original.');
        Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota del libro.',
        ]);
        BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'table',
            'label' => 'Tabla 1',
            'caption' => 'Cronología',
            'position' => 1,
        ]);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['txt', 'docx', 'epub', 'pdf'],
            ])->assertCreated()->json('manifest');

        $stored = ExportManifest::findOrFail($manifest['id']);
        $this->assertSame(['txt', 'docx', 'epub', 'pdf'], $stored->formats);
        $this->assertSame('Texto original.', $stored->manifest['chapters'][0]['blocks'][1]['text']);

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'editado', 'type' => 'paragraph', 'text' => 'Texto editado después.']],
            ],
        ])->save();
        $this->chapterWithScene($novel, 'Dos', 'Capítulo posterior.');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/txt")
            ->assertOk();

        $txt = $response->getContent();
        $this->assertStringContainsString('Texto original.', $txt);
        $this->assertStringNotContainsString('Texto editado después.', $txt);
        $this->assertStringNotContainsString('Capítulo posterior.', $txt);
        $this->assertStringContainsString('La carta', $txt);
        $this->assertStringContainsString('PRÓLOGO', $txt);
        $this->assertStringContainsString('Uno', $txt);
        $this->assertStringContainsString('NOTAS', $txt);
        $this->assertStringContainsString('[Pie 1] Nota del libro.', $txt);
        $this->assertStringContainsString('AVISO:', $txt);
        $this->assertStringContainsString('omite formato visual', $response->headers->get('X-Export-Warnings'));
        $this->assertTrue(mb_check_encoding($txt, 'UTF-8'));

        $stored->update(['formats' => ['txt']]);
        $this->assertSame(['txt', 'docx', 'epub', 'pdf'], $stored->fresh()->formats);
        $stored->delete();
        $this->assertDatabaseHas('export_manifests', ['id' => $manifest['id']]);

        $this->asWriter($this->writer())
            ->get("/workspace/export-manifests/{$manifest['id']}/txt")
            ->assertNotFound();
    }

    public function test_docx_contains_styles_tables_notes_and_valid_xml_parts(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);

        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto con nota.');
        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Contenido de la nota.',
        ]);
        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Texto con nota.',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 14,
                        'end' => 14,
                        'attrs' => ['note_id' => $note->id],
                    ]],
                ]],
            ],
            'revision' => 2,
        ])->save();
        BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'table',
            'label' => 'Tabla 1',
            'caption' => 'Cronología',
            'position' => 1,
        ]);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx']])
            ->assertCreated()
            ->json('manifest');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/docx")
            ->assertOk();

        $this->assertStringContainsString('paginación', $response->headers->get('X-Export-Warnings'));

        $path = tempnam(sys_get_temp_dir(), 'docxtest');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        foreach (['[Content_Types].xml', 'word/document.xml', 'word/styles.xml', 'word/footnotes.xml'] as $required) {
            $this->assertContains($required, $names);
        }

        $document = (string) $zip->getFromName('word/document.xml');
        $styles = (string) $zip->getFromName('word/styles.xml');
        $footnotes = (string) $zip->getFromName('word/footnotes.xml');

        foreach ([$document, $styles, $footnotes] as $xml) {
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($xml), 'Cada parte DOCX debe ser XML bien formado.');
        }

        $this->assertStringContainsString('w:tbl', $document);
        $this->assertStringContainsString('w:footnoteReference', $document);
        $this->assertStringContainsString('CuerpoLibro', $styles);
        $this->assertStringContainsString('Contenido de la nota.', $footnotes);

        $zip->close();
        unlink($path);
    }

    public function test_docx_and_epub_use_the_profile_font_families(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        Storage::fake('local');
        Storage::disk('local')->put('fonts/test/body.ttf', 'body-font-bytes');
        Storage::disk('local')->put('fonts/test/heading.otf', 'heading-font-bytes');

        $body = Font::create([
            'owner_id' => $user->id,
            'family' => 'Crimson Text',
            'variant' => 'Regular',
            'path' => 'fonts/test/body.ttf',
            'bytes' => 15,
            'license' => 'OFL-1.1',
            'metadata' => ['format' => 'ttf'],
        ]);
        $heading = Font::create([
            'owner_id' => $user->id,
            'family' => 'Source Serif 4',
            'variant' => 'Regular',
            'path' => 'fonts/test/heading.otf',
            'bytes' => 18,
            'license' => 'OFL-1.1',
            'metadata' => ['format' => 'otf'],
        ]);
        $this->profileFor($novel, [
            'fonts' => ['body_font_id' => $body->id, 'heading_font_id' => $heading->id],
        ]);
        $this->chapterWithScene($novel, 'Uno', 'Texto con fuente.');

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx', 'epub']])
            ->assertCreated()
            ->json('manifest');

        $docxPath = tempnam(sys_get_temp_dir(), 'docxfont');
        file_put_contents($docxPath, $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/docx")
            ->assertOk()
            ->getContent());
        $docx = new ZipArchive;
        $this->assertTrue($docx->open($docxPath) === true);
        $styles = (string) $docx->getFromName('word/styles.xml');
        $this->assertStringContainsString('Crimson Text', $styles);
        $this->assertStringContainsString('Source Serif 4', $styles);
        $docx->close();
        unlink($docxPath);

        $epubPath = tempnam(sys_get_temp_dir(), 'epubfont');
        file_put_contents($epubPath, $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/epub")
            ->assertOk()
            ->getContent());
        $epub = new ZipArchive;
        $this->assertTrue($epub->open($epubPath) === true);
        $this->assertSame('body-font-bytes', $epub->getFromName('OEBPS/fonts/'.$body->id.'.ttf'));
        $this->assertSame('heading-font-bytes', $epub->getFromName('OEBPS/fonts/'.$heading->id.'.otf'));
        $css = (string) $epub->getFromName('OEBPS/style.css');
        $this->assertStringContainsString("@font-face { font-family: 'Crimson Text'", $css);
        $this->assertStringContainsString("@font-face { font-family: 'Source Serif 4'", $css);
        $this->assertStringContainsString("font-family: 'Crimson Text'", $css);
        $this->assertStringContainsString("font-family: 'Source Serif 4'", $css);
        $opf = (string) $epub->getFromName('OEBPS/package.opf');
        $this->assertStringContainsString('media-type="font/ttf"', $opf);
        $this->assertStringContainsString('media-type="font/otf"', $opf);
        $epub->close();
        unlink($epubPath);
    }

    public function test_docx_places_note_reference_at_its_mark_position(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Antes despues');

        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota en su sitio.',
        ]);

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Antes despues',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 6,
                        'end' => 6,
                        'attrs' => ['note_id' => $note->id],
                    ]],
                ]],
            ],
            'revision' => 2,
        ])->save();

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx']])
            ->assertCreated()
            ->json('manifest');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/docx")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'docxnote');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        $before = strpos($document, 'Antes');
        $reference = strpos($document, 'w:footnoteReference');
        $after = strpos($document, 'despues');

        $this->assertNotFalse($before);
        $this->assertNotFalse($reference);
        $this->assertNotFalse($after);
        $this->assertLessThan($reference, $before, 'El texto anterior debe preceder a la referencia.');
        $this->assertLessThan($after, $reference, 'La referencia debe preceder al texto posterior.');
    }

    public function test_epub_places_note_reference_at_mark_and_exports_front_and_back_matter(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Antes despues');

        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'front',
            'type' => 'dedication',
            'title' => 'Dedicatoria',
            'content' => 'Para quienes leen.',
            'position' => 0,
            'include_in_export' => true,
        ]);
        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'back',
            'type' => 'epilogue',
            'title' => 'Epílogo',
            'content' => 'Fin de la historia.',
            'position' => 0,
            'include_in_export' => true,
        ]);

        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota EPUB.',
        ]);

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Antes despues',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 6,
                        'end' => 6,
                        'attrs' => ['note_id' => $note->id],
                    ]],
                ]],
            ],
            'revision' => 2,
        ])->save();

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['epub']])
            ->assertCreated()
            ->json('manifest');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/epub")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'epubnote');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $chapter = (string) $zip->getFromName('OEBPS/chapter-1.xhtml');
        $front = (string) $zip->getFromName('OEBPS/front-1.xhtml');
        $back = (string) $zip->getFromName('OEBPS/back-1.xhtml');
        $package = (string) $zip->getFromName('OEBPS/package.opf');
        $zip->close();
        unlink($path);

        $before = strpos($chapter, 'Antes');
        $reference = strpos($chapter, 'noteref');
        $after = strpos($chapter, 'despues');

        $this->assertNotFalse($before);
        $this->assertNotFalse($reference);
        $this->assertNotFalse($after);
        $this->assertLessThan($reference, $before);
        $this->assertLessThan($after, $reference);

        $this->assertStringContainsString('Para quienes leen.', $front);
        $this->assertStringContainsString('Fin de la historia.', $back);
        $this->assertStringContainsString('front-1.xhtml', $package);
        $this->assertStringContainsString('back-1.xhtml', $package);
    }

    public function test_epub_has_stored_mimetype_navigation_chapter_and_linked_notes(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);

        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto EPUB.');
        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota enlazada.',
        ]);
        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Texto EPUB.',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 10,
                        'end' => 10,
                        'attrs' => ['note_id' => $note->id],
                    ]],
                ]],
            ],
            'revision' => 2,
        ])->save();

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['epub']])
            ->assertCreated()
            ->json('manifest');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/epub")
            ->assertOk();

        $this->assertStringContainsString('EPUBCheck', $response->headers->get('X-Export-Warnings'));

        $path = tempnam(sys_get_temp_dir(), 'epubtest');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $this->assertSame('mimetype', $zip->getNameIndex(0));
        $this->assertSame(ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));

        foreach ([
            'META-INF/container.xml',
            'OEBPS/package.opf',
            'OEBPS/nav.xhtml',
            'OEBPS/chapter-1.xhtml',
            'OEBPS/notes.xhtml',
        ] as $required) {
            $this->assertNotFalse($zip->locateName($required), "Falta {$required}");
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML((string) $zip->getFromName($required)));
        }

        $package = (string) $zip->getFromName('OEBPS/package.opf');
        $nav = (string) $zip->getFromName('OEBPS/nav.xhtml');
        $chapter = (string) $zip->getFromName('OEBPS/chapter-1.xhtml');
        $notes = (string) $zip->getFromName('OEBPS/notes.xhtml');

        $this->assertStringContainsString('version="3.0"', $package);
        $this->assertStringContainsString('properties="nav"', $package);
        $this->assertStringContainsString('epub:type="toc"', $nav);
        $this->assertStringContainsString('epub:type="noteref"', $chapter);
        $this->assertStringContainsString('fnref-', $chapter);
        $this->assertStringContainsString('epub:type="footnote"', $notes);
        $this->assertStringContainsString('chapter-1.xhtml#fnref', $notes);

        $zip->close();
        unlink($path);
    }

    public function test_pdf_endpoint_serves_a_client_export_page_without_print(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        $this->chapterWithScene($novel, 'Uno', 'Texto para exportar.');

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['pdf']])
            ->assertCreated()
            ->json('manifest');

        $response = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/pdf")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));

        $html = $response->getContent();
        $this->assertStringContainsString('id="pdf-input"', $html);
        $this->assertStringContainsString('id="pdf-resources"', $html);
        $this->assertStringContainsString('Texto para exportar.', $html);
        $this->assertStringContainsString('/build/assets/export-', $html);
        $this->assertStringNotContainsString('window.print', $html);
        $this->assertStringNotContainsString('Guardar como PDF', $html);
        $this->assertStringNotContainsString('@page', $html);

        foreach (['resources/js/pdf/export.js', 'resources/js/pdf/layout.js'] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString('window.print', $source);
            $this->assertStringNotContainsString('print()', $source);
            $this->assertStringNotContainsString('SaveAsPDF', $source);
        }
    }

    public function test_export_manifest_uses_the_selected_saved_snapshot_and_rejects_unknown_or_foreign_ids(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto guardado.');

        $snapshots = app(SnapshotService::class);
        $saved = $snapshots->capture($novel, $user, 'Versión guardada');

        $scene->forceFill([
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'actual', 'type' => 'paragraph', 'text' => 'Texto actual.']]],
            'revision' => 2,
        ])->save();

        $manifestId = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['txt'],
                'snapshot_id' => $saved->id,
            ])->assertCreated()->json('manifest.id');

        $txt = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifestId}/txt")
            ->assertOk()->getContent();

        $this->assertStringContainsString('Texto guardado.', $txt);
        $this->assertStringNotContainsString('Texto actual.', $txt);

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['txt'],
                'snapshot_id' => 999999,
            ])->assertStatus(422);

        $other = $this->writer();
        $foreign = $snapshots->capture($this->novelFor($other), $other, 'Ajena');

        $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['txt'],
                'snapshot_id' => $foreign->id,
            ])->assertStatus(422);
    }

    public function test_saved_snapshot_freezes_notes_metadata_front_matter_and_profile(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->profileFor($novel);
        BookMetadata::create(['novel_id' => $novel->id, 'title' => 'Título guardado', 'language' => 'es']);
        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'front',
            'type' => 'prologue',
            'title' => 'Prólogo guardado',
            'content' => 'Texto preliminar guardado.',
            'position' => 0,
            'include_in_export' => true,
        ]);
        [, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto guardado.');
        Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota guardada.',
        ]);

        $saved = app(SnapshotService::class)->capture($novel, $user, 'Versión completa');

        BookMetadata::where('novel_id', $novel->id)->update(['title' => 'Título actual']);
        BookSection::where('novel_id', $novel->id)->update([
            'title' => 'Prólogo actual',
            'content' => 'Texto preliminar actual.',
        ]);
        Footnote::where('novel_id', $novel->id)->update(['text' => 'Nota actual.']);
        BookProfile::where('novel_id', $novel->id)->update(['name' => 'Perfil actual']);
        $scene->forceFill([
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'actual', 'type' => 'paragraph', 'text' => 'Texto actual.']]],
            'revision' => 2,
        ])->save();

        $manifestId = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['txt'],
                'snapshot_id' => $saved->id,
            ])->assertCreated()->json('manifest.id');

        $txt = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifestId}/txt")
            ->assertOk()->getContent();

        foreach (['Título guardado', 'PRÓLOGO GUARDADO', 'Texto preliminar guardado.', 'Texto guardado.', 'Nota guardada.'] as $expected) {
            $this->assertStringContainsString($expected, $txt);
        }

        foreach (['Título actual', 'PRÓLOGO ACTUAL', 'Texto preliminar actual.', 'Texto actual.', 'Nota actual.'] as $stale) {
            $this->assertStringNotContainsString($stale, $txt);
        }

        $this->assertSame('Rústica 148x210', ExportManifest::findOrFail($manifestId)->manifest['profile']['name']);
    }

    public function test_saved_snapshot_freezes_profile_font_and_media_resource_context(): void
    {
        Storage::fake('local');

        $user = $this->writer();
        $novel = $this->novelFor($user);

        Storage::disk('local')->put('fonts/old.ttf', 'OLD-FONT');
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Old Family',
            'variant' => 'Regular',
            'path' => 'fonts/old.ttf',
            'bytes' => 8,
            'license' => 'OFL-1.1',
            'metadata' => ['format' => 'ttf'],
        ]);

        Storage::disk('local')->put('media/old.png', 'OLD-IMG');
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'old.png',
            'mime' => 'image/png',
            'bytes' => 7,
            'path' => 'media/old.png',
            'metadata' => ['width' => 10, 'height' => 10],
        ]);

        $this->profileFor($novel, [
            'fonts' => ['body_font_id' => $font->id],
            'cover' => ['asset_id' => $asset->id],
        ]);
        $this->chapterWithScene($novel, 'Uno', 'Con figura.', [
            ['id' => 'img-1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Figura']],
        ]);

        $saved = app(SnapshotService::class)->capture($novel, $user, 'Recursos congelados');

        $font->forceFill(['family' => 'New Family', 'path' => 'fonts/new.ttf'])->save();
        Storage::disk('local')->put('fonts/new.ttf', 'NEW-FONT');
        $asset->forceFill(['path' => 'media/new.png', 'original_name' => 'new.png'])->save();
        Storage::disk('local')->put('media/new.png', 'NEW-IMG');

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", [
                'formats' => ['pdf'],
                'snapshot_id' => $saved->id,
            ])
            ->assertCreated()
            ->json('manifest');

        $frozen = ExportManifest::findOrFail($manifest['id'])->manifest;

        $fontEntry = collect($frozen['resources']['fonts'])->firstWhere('id', $font->id);
        $this->assertNotNull($fontEntry);
        $this->assertSame('Old Family', $fontEntry['family']);
        $this->assertSame('fonts/old.ttf', $fontEntry['path']);
        $this->assertTrue($fontEntry['exists']);

        $mediaEntry = collect($frozen['resources']['media'])->firstWhere('id', $asset->id);
        $this->assertNotNull($mediaEntry);
        $this->assertSame('media/old.png', $mediaEntry['path']);
        $this->assertSame('old.png', $mediaEntry['original_name']);
        $this->assertTrue($mediaEntry['exists']);
    }

    public function test_pdf_client_input_embeds_profile_fonts_media_and_index(): void
    {
        Storage::fake('local');

        $user = $this->writer();
        $novel = $this->novelFor($user);

        Storage::disk('local')->put('fonts/cuerpo.ttf', 'TTF-BYTES');
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Cuerpo Prueba',
            'variant' => 'Regular',
            'path' => 'fonts/cuerpo.ttf',
            'bytes' => 9,
            'license' => 'OFL-1.1',
            'metadata' => ['format' => 'ttf'],
        ]);
        Storage::disk('local')->put('media/figura.png', 'PNG-BYTES');
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'figura.png',
            'mime' => 'image/png',
            'bytes' => 9,
            'path' => 'media/figura.png',
            'metadata' => ['width' => 10, 'height' => 10],
        ]);

        $this->profileFor($novel, ['fonts' => ['body_font_id' => $font->id]]);
        [$chapter, , $scene] = $this->chapterWithScene($novel, 'Uno', 'Texto con figura.', [
            ['id' => 'img-1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Figura uno', 'caption' => 'Pie de figura']],
        ]);
        BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'index',
            'label' => 'Arven',
            'caption' => 'Reino',
            'target_type' => 'chapter',
            'target_id' => $chapter->id,
        ]);
        BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'index',
            'label' => 'Puente',
            'caption' => 'Lugar',
            'target_type' => 'scene',
            'target_id' => $scene->id,
        ]);
        $figure = BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'figure',
            'label' => 'Figura 1',
            'caption' => 'Mapa',
            'target_type' => 'scene',
            'target_id' => $scene->id,
        ]);
        BookMark::create([
            'novel_id' => $novel->id,
            'type' => 'index',
            'label' => 'Mapa',
            'caption' => 'Figura',
            'target_type' => 'figure',
            'target_id' => $figure->id,
        ]);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['pdf']])
            ->assertCreated()
            ->json('manifest');

        $html = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/pdf")
            ->assertOk()
            ->getContent();

        $input = $this->jsonScript($html, 'pdf-input');
        $resources = $this->jsonScript($html, 'pdf-resources');

        $image = collect($input['chapters'][0]['blocks'])->firstWhere('type', 'image');
        $this->assertNotNull($image);
        $this->assertSame($asset->id, $image['asset_id']);
        $this->assertSame('Pie de figura', $image['caption']);

        $this->assertStringContainsString('/pdf-resource/font/'.$font->id, $resources['fonts']['body']);
        $this->assertStringContainsString('/pdf-resource/media/'.$asset->id, (string) ($resources['media'][$asset->id] ?? ''));

        $this->assertSame('Arven', $input['index'][0]['term']);
        $this->assertSame('Uno', $input['index'][0]['ref']);
        $this->assertSame('chapter:'.$chapter->id, $input['index'][0]['page_ref']);

        $sceneIndex = collect($input['index'])->firstWhere('term', 'Puente');
        $this->assertNotNull($sceneIndex);
        $this->assertSame('Uno', $sceneIndex['ref']);
        $this->assertSame('scene:'.$scene->id, $sceneIndex['page_ref']);

        $figureIndex = collect($input['index'])->firstWhere('term', 'Mapa');
        $this->assertNotNull($figureIndex);
        $this->assertSame('scene:'.$scene->id, $figureIndex['page_ref']);

        $fontPath = parse_url($resources['fonts']['body'], PHP_URL_PATH);
        $mediaPath = parse_url($resources['media'][$asset->id], PHP_URL_PATH);

        $this->asWriter($user)->get($fontPath)->assertOk()->assertHeader('Content-Type', 'font/ttf');
        $this->asWriter($user)->get($mediaPath)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->asWriter($this->writer())->get($fontPath)->assertNotFound();
    }

    private function jsonScript(string $html, string $id): array
    {
        if (! preg_match('/<script type="application\\/json" id="'.$id.'">(.*?)<\\/script>/s', $html, $matches)) {
            return [];
        }

        return json_decode($matches[1], true) ?? [];
    }

    public function test_docx_and_epub_include_manifest_images(): void
    {
        Storage::fake('local');

        $user = $this->writer();
        $novel = $this->novelFor($user);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('local')->put('media/g03.png', $png);
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'g03.png',
            'mime' => 'image/png',
            'bytes' => strlen($png),
            'path' => 'media/g03.png',
            'metadata' => ['width' => 1, 'height' => 1],
        ]);
        $this->profileFor($novel);
        $this->chapterWithScene($novel, 'Uno', 'Con figura.', [
            ['id' => 'img-1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Figura', 'caption' => 'Pie de figura']],
        ]);

        $manifest = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/export-manifests", ['formats' => ['docx', 'epub']])
            ->assertCreated()
            ->json('manifest');

        $docx = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/docx")
            ->assertOk()
            ->getContent();
        $docxPath = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($docxPath, $docx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath) === true);
        $mediaEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'word/media/')) {
                $mediaEntries[] = $name;
            }
        }
        $documentXml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($docxPath);
        $this->assertNotEmpty($mediaEntries, 'DOCX must embed the image bytes');
        $this->assertMatchesRegularExpression('/<w:drawing>|imagedata/', $documentXml);

        $epub = $this->asWriter($user)
            ->get("/workspace/export-manifests/{$manifest['id']}/epub")
            ->assertOk()
            ->getContent();
        $epubPath = tempnam(sys_get_temp_dir(), 'epub');
        file_put_contents($epubPath, $epub);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($epubPath) === true);
        $epubMedia = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'OEBPS/media/')) {
                $epubMedia[] = $name;
            }
        }
        $chapter = (string) $zip->getFromName('OEBPS/chapter-1.xhtml');
        $zip->close();
        unlink($epubPath);
        $this->assertNotEmpty($epubMedia, 'EPUB must include the image file');
        $this->assertStringContainsString('<img', $chapter);
        $this->assertStringContainsString('Pie de figura', $chapter);
    }
}
