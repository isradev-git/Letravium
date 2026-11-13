<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardRelationship;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\Invitation;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Style;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;
use ZipArchive;

class ImportsTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function fake(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

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

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
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
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>',
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>',
        );
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>Capítulo 1</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Primer párrafo.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Capítulo 2</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Segundo párrafo.</w:t></w:r></w:p>'
            .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Dato</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            .'</w:body></w:document>',
        );
        $zip->addFromString(
            'word/_rels/document.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://example.invalid/external.png" TargetMode="External"/>'
            .'</Relationships>',
        );
        $zip->addFromString('word/media/image1.png', $this->pngBytes());
        $zip->addFromString('word/vbaProject.bin', 'MZ'.str_repeat("\0", 32));
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_txt_preview_normalizes_line_endings_and_proposes_editable_chapters_without_committing(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $text = "\xEF\xBB\xBFCapítulo 1\r\n\r\nEl faro.\rLa carta.\r\n\r\nCAPÍTULO II\r\nLa tormenta.\r\n";

        $json = $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('manuscrito.txt', $text),
                'destination' => ['mode' => 'append', 'novel_id' => $novel->id],
            ])->assertOk()->json();

        $this->assertSame('txt', $json['source']['format']);
        $this->assertSame('UTF-8', $json['source']['encoding']);
        $this->assertCount(2, $json['chapters']);
        $this->assertSame('Capítulo 1', $json['chapters'][0]['title']);
        $this->assertSame("El faro.\nLa carta.", $json['chapters'][0]['text']);
        $this->assertSame('CAPÍTULO II', $json['chapters'][1]['title']);
        $this->assertSame('La tormenta.', $json['chapters'][1]['text']);
        $this->assertStringNotContainsString("\r", implode("\n", array_column($json['chapters'], 'text')));
        $this->assertNotEmpty($json['token']);

        $this->assertDatabaseCount('chapters', 0);
        $this->assertDatabaseCount('novels', 1);
        Storage::disk('local')->assertExists("imports/{$user->id}/{$json['token']}/source.txt");
    }

    public function test_txt_preview_rejects_invalid_encoding_and_oversized_files(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();

        $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('roto.txt', "\xFF\xFE\x00\x00"),
            ])->assertStatus(422);

        $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('grande.txt', str_repeat('A', 10 * 1024 * 1024 + 1)),
            ])->assertStatus(422);
    }

    public function test_docx_preview_inventories_headings_tables_images_notes_and_unsupported_content_without_fetching_it(): void
    {
        Storage::fake('local');
        $user = $this->writer();

        $json = $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('manuscrito.docx', $this->docxBytes()),
                'destination' => ['mode' => 'new_novel', 'universe_id' => $this->universeFor($user)->id],
            ])->assertOk()->json();

        $this->assertSame('docx', $json['source']['format']);
        $this->assertCount(2, $json['chapters']);
        $this->assertSame('Capítulo 1', $json['chapters'][0]['title']);
        $this->assertStringContainsString('Primer párrafo.', $json['chapters'][0]['text']);
        $this->assertSame(1, $json['inventory']['tables']);
        $this->assertSame(1, $json['inventory']['images']);
        $this->assertGreaterThan(0, count($json['inventory']['headings']));

        $unsupported = array_column($json['unsupported'], 'type');
        $this->assertContains('external_relationship', $unsupported);
        $this->assertContains('macro', $unsupported);
        $this->assertNotEmpty($json['losses']);

        $this->assertDatabaseCount('chapters', 0);
        Storage::disk('local')->assertExists("imports/{$user->id}/{$json['token']}/source.docx");
    }

    public function test_commit_appends_reviewed_chapters_and_failure_rolls_back_without_partial_novel(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $preview = $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('manuscrito.txt', "Capítulo 1\nTexto uno.\nCapítulo 2\nTexto dos.\n"),
                'destination' => ['mode' => 'append', 'novel_id' => $novel->id],
            ])->assertOk()->json();

        $this->asWriter($user)
            ->postJson('/workspace/imports/commit', [
                'token' => $preview['token'],
                'mode' => 'append',
                'novel_id' => $novel->id,
                'chapters' => [
                    ['title' => 'Uno revisado', 'text' => "Primer párrafo.\nSegundo párrafo."],
                    ['title' => 'Dos revisado', 'text' => 'Tercer párrafo.'],
                ],
            ])->assertCreated();

        $this->assertDatabaseCount('chapters', 2);
        $this->assertDatabaseCount('scenes', 2);
        $this->assertSame('Uno revisado', Chapter::orderBy('position')->first()->title);

        $failurePreview = $this->asWriter($user)
            ->post('/workspace/imports/preview', [
                'file' => $this->fake('fallo.txt', "Capítulo fallido\nNo debe persistir.\n"),
                'destination' => ['mode' => 'append', 'novel_id' => $novel->id],
            ])->assertOk()->json();

        Event::listen('eloquent.creating: '.Scene::class, function (): void {
            throw new \RuntimeException('Fallo simulado al preparar recursos.');
        });

        $before = [Chapter::count(), Scene::count(), ChapterAlternative::count()];

        $this->asWriter($user)
            ->postJson('/workspace/imports/commit', [
                'token' => $failurePreview['token'],
                'mode' => 'append',
                'novel_id' => $novel->id,
                'chapters' => [['title' => 'Fallido', 'text' => 'No debe persistir.']],
            ])->assertStatus(500);

        $this->assertSame($before, [Chapter::count(), Scene::count(), ChapterAlternative::count()]);
        $this->assertDatabaseMissing('chapters', ['title' => 'Fallido']);
        Storage::disk('local')->assertExists("imports/{$user->id}/{$failurePreview['token']}/source.txt");
    }

    public function test_backup_package_excludes_auth_secrets_invitations_and_foreign_notes(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $user->forceFill([
            'password' => bcrypt('clave-secreta'),
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => ['RECOVERY-SECRETO'],
        ])->save();

        Invitation::create(['email' => 'invitacion@example.test', 'token' => 'TOKEN-INVITACION']);

        $universe = $this->universeFor($user);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $card = Card::create([
            'universe_id' => $universe->id,
            'area' => 'characters',
            'name' => 'Elena',
            'text' => ['body' => 'Nota propia admitida'],
        ]);

        $foreignUser = $this->writer();
        $foreignUniverse = Universe::create(['owner_id' => $foreignUser->id, 'name' => 'Ajena']);
        Card::create([
            'universe_id' => $foreignUniverse->id,
            'area' => 'notes',
            'name' => 'Secreto',
            'text' => ['body' => 'NOTA-AJENA-PRIVADA'],
        ]);

        Storage::disk('local')->put("media/{$user->id}/mapa.png", $this->pngBytes());
        MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'mapa.png',
            'mime' => 'image/png',
            'bytes' => strlen($this->pngBytes()),
            'path' => "media/{$user->id}/mapa.png",
        ]);

        $response = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $path = $response->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $content = (string) $zip->getFromName('content.json');
        $all = $content;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $all .= (string) $zip->getFromName($name);
            if (str_starts_with($name, 'resources/')) {
                $this->assertArrayHasKey($name, $manifest['files']);
                $this->assertSame(hash('sha256', (string) $zip->getFromName($name)), $manifest['files'][$name]['sha256']);
            }
        }

        $this->assertSame('escritor-universe-backup', $manifest['format']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(hash('sha256', $content), $manifest['files']['content.json']['sha256']);
        $this->assertSame($card->name, json_decode($content, true)['cards'][0]['name']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $all);
        $this->assertStringNotContainsString('RECOVERY-SECRETO', $all);
        $this->assertStringNotContainsString('invitacion@example.test', $all);
        $this->assertStringNotContainsString('TOKEN-INVITACION', $all);
        $this->assertStringNotContainsString('NOTA-AJENA-PRIVADA', $all);
        $this->assertStringNotContainsString('clave-secreta', $all);

        $zip->close();
    }

    public function test_restore_rejects_tampered_packages_and_remaps_internal_identities_in_a_new_private_universe(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $elena = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $valdara = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Valdara']);
        CardRelationship::create([
            'universe_id' => $universe->id,
            'source_card_id' => $elena->id,
            'target_card_id' => $valdara->id,
            'label' => 'teme a',
        ]);

        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'pov_card_id' => $elena->id,
            'place_card_id' => $valdara->id,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'El puente.']]],
        ]);

        Storage::disk('local')->put("media/{$user->id}/mapa.png", $this->pngBytes());
        MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'mapa.png',
            'mime' => 'image/png',
            'bytes' => strlen($this->pngBytes()),
            'path' => "media/{$user->id}/mapa.png",
        ]);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $path = $export->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('content.json', $zip->getFromName('content.json').' ');
        $zip->close();

        $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($path, 'copia.zip', 'application/zip', null, true),
            ])->assertStatus(422);

        $this->assertDatabaseCount('universes', 1);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();
        $path = $export->getFile()->getPathname();
        $invitations = Invitation::count();

        $restored = $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($path, 'copia.zip', 'application/zip', null, true),
            ])->assertCreated()->json('universe');

        $this->assertNotSame($universe->id, $restored['id']);
        $this->assertSame($user->id, $restored['owner_id']);
        $this->assertSame('Arven (restaurado)', $restored['name']);

        $cards = Card::where('universe_id', $restored['id'])->orderBy('name')->get();
        $this->assertCount(2, $cards);
        $this->assertNotContains($elena->id, $cards->pluck('id')->all());
        $this->assertNotContains($valdara->id, $cards->pluck('id')->all());

        $relationship = CardRelationship::where('universe_id', $restored['id'])->firstOrFail();
        $this->assertContains($relationship->source_card_id, $cards->pluck('id')->all());
        $this->assertContains($relationship->target_card_id, $cards->pluck('id')->all());

        $newNovel = Novel::where('universe_id', $restored['id'])->firstOrFail();
        $this->assertNotSame($novel->id, $newNovel->id);
        $newScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn(
                'chapter_id',
                Chapter::where('novel_id', $newNovel->id)->pluck('id'),
            )->pluck('id'),
        )->firstOrFail();
        $this->assertContains($newScene->pov_card_id, $cards->pluck('id')->all());
        $this->assertContains($newScene->place_card_id, $cards->pluck('id')->all());

        $asset = MediaAsset::where('universe_id', $restored['id'])->firstOrFail();
        $this->assertSame($user->id, $asset->owner_id);
        Storage::disk('local')->assertExists($asset->path);

        $this->assertSame($invitations, Invitation::count());
    }

    public function test_backup_includes_content_revisions_and_history_entries(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Original']]],
        ]);

        DB::table('content_revisions')->insert([
            'scene_id' => $scene->id,
            'revision' => 1,
            'content' => json_encode($scene->content),
            'author_id' => $user->id,
            'reason' => 'save',
            'created_at' => now(),
        ]);
        DB::table('history_entries')->insert([
            'novel_id' => $novel->id,
            'resource_type' => 'scene',
            'resource_id' => $scene->id,
            'revision' => 1,
            'payload' => json_encode($scene->content),
            'reason' => 'save',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $restored = $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
            ])->assertCreated()->json('universe');

        $newNovel = Novel::where('universe_id', $restored['id'])->firstOrFail();
        $newScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn(
                'chapter_id',
                Chapter::where('novel_id', $newNovel->id)->pluck('id'),
            )->pluck('id'),
        )->firstOrFail();

        $this->assertDatabaseHas('content_revisions', ['scene_id' => $newScene->id, 'revision' => 1, 'author_id' => $user->id]);
        $this->assertDatabaseHas('history_entries', [
            'novel_id' => $newNovel->id,
            'resource_type' => 'scene',
            'resource_id' => $newScene->id,
            'revision' => 1,
        ]);
    }

    public function test_backup_includes_owner_snapshots_and_comments_but_not_beta_or_foreign_comments(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $other = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto']]],
        ]);

        $snapshotId = DB::table('snapshots')->insertGetId([
            'novel_id' => $novel->id,
            'name' => 'Versión propia',
            'manifest' => json_encode(['format_version' => 3, 'novel' => ['id' => $novel->id]]),
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        DB::table('snapshot_items')->insert([
            'snapshot_id' => $snapshotId,
            'resource_type' => 'scene',
            'resource_id' => $scene->id,
            'revision' => 1,
            'payload' => json_encode(['id' => $scene->id, 'chapter_alternative_id' => $alternative->id, 'content' => $scene->content]),
        ]);
        $ownerComment = DB::table('comments')->insertGetId([
            'novel_id' => $novel->id,
            'chapter_id' => $chapter->id,
            'scene_id' => $scene->id,
            'snapshot_id' => $snapshotId,
            'block_id' => 'b1',
            'quoted_text' => 'PROPIO',
            'status' => 'open',
            'author_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('comments')->insert([
            'novel_id' => $novel->id,
            'chapter_id' => $chapter->id,
            'scene_id' => $scene->id,
            'snapshot_id' => $snapshotId,
            'block_id' => 'b1',
            'quoted_text' => 'AJENO',
            'status' => 'open',
            'author_id' => $other->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('comment_messages')->insert([
            'comment_id' => $ownerComment,
            'author_id' => $user->id,
            'body' => 'Mensaje propio',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('comment_messages')->insert([
            'comment_id' => $ownerComment,
            'author_id' => $other->id,
            'body' => 'Mensaje ajeno',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('beta_readers')->insert([
            'snapshot_id' => $snapshotId,
            'user_id' => $other->id,
            'token' => 'token-beta',
            'expires_at' => now()->addDay(),
            'can_comment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $restored = $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
            ])->assertCreated()->json('universe');

        $newNovel = Novel::where('universe_id', $restored['id'])->firstOrFail();
        $newScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn('chapter_id', Chapter::where('novel_id', $newNovel->id)->pluck('id'))->pluck('id'),
        )->firstOrFail();
        $newSnapshot = DB::table('snapshots')->where('novel_id', $newNovel->id)->where('name', 'Versión propia')->first();
        $this->assertNotNull($newSnapshot);
        $this->assertSame($newNovel->id, json_decode($newSnapshot->manifest, true)['novel']['id']);
        $this->assertDatabaseHas('snapshot_items', ['snapshot_id' => $newSnapshot->id, 'resource_type' => 'scene', 'resource_id' => $newScene->id]);
        $this->assertDatabaseHas('comments', ['novel_id' => $newNovel->id, 'scene_id' => $newScene->id, 'snapshot_id' => $newSnapshot->id, 'quoted_text' => 'PROPIO']);
        $this->assertDatabaseMissing('comments', ['novel_id' => $newNovel->id, 'quoted_text' => 'AJENO']);
        $newComment = DB::table('comments')->where('novel_id', $newNovel->id)->where('quoted_text', 'PROPIO')->first();
        $this->assertDatabaseHas('comment_messages', ['comment_id' => $newComment->id, 'body' => 'Mensaje propio']);
        $this->assertDatabaseMissing('comment_messages', ['comment_id' => $newComment->id, 'body' => 'Mensaje ajeno']);
        $this->assertDatabaseMissing('beta_readers', ['snapshot_id' => $newSnapshot->id]);
    }

    public function test_backup_includes_notes_chronology_glossary_roles_and_custody_with_new_ids(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto']]],
        ]);
        $character = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);
        $place = Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Puerto']);
        $language = Card::create(['universe_id' => $universe->id, 'area' => 'languages', 'name' => 'Antiguo']);
        $object = Card::create(['universe_id' => $universe->id, 'area' => 'objects', 'name' => 'Reliquia']);

        $noteId = DB::table('notes')->insertGetId([
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'author_id' => $user->id,
            'title' => 'Nota privada',
            'body' => 'Contenido',
            'private' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('note_links')->insert([
            'note_id' => $noteId,
            'linkable_type' => 'scene',
            'linkable_id' => $scene->id,
            'anchor' => 'Texto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $calendarA = DB::table('calendars')->insertGetId([
            'universe_id' => $universe->id,
            'name' => 'Era común',
            'config' => json_encode(['months' => 12]),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $calendarB = DB::table('calendars')->insertGetId([
            'universe_id' => $universe->id,
            'name' => 'Era antigua',
            'config' => json_encode(['months' => 10]),
            'is_current' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('calendar_conversions')->insert([
            'universe_id' => $universe->id,
            'from_calendar_id' => $calendarA,
            'to_calendar_id' => $calendarB,
            'from_label' => 'Año 1',
            'to_label' => 'Año 100',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventA = DB::table('events')->insertGetId([
            'universe_id' => $universe->id,
            'name' => 'Fundación',
            'type' => 'event',
            'participants' => json_encode([$character->id]),
            'places' => json_encode([$place->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $eventB = DB::table('events')->insertGetId([
            'universe_id' => $universe->id,
            'name' => 'Caída',
            'type' => 'event',
            'participants' => json_encode([$character->id]),
            'places' => json_encode([$place->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('event_relations')->insert([
            'universe_id' => $universe->id,
            'from_event_id' => $eventA,
            'to_event_id' => $eventB,
            'relation' => 'before',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('story_changes')->insert([
            'universe_id' => $universe->id,
            'card_id' => $character->id,
            'field' => 'status',
            'value' => json_encode('vivo'),
            'effective_event_id' => $eventA,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('story_contexts')->insert([
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'card_id' => $character->id,
            'event_id' => $eventA,
            'context' => json_encode(['card_id' => $character->id]),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('object_custodies')->insert([
            'universe_id' => $universe->id,
            'card_id' => $object->id,
            'holder_card_id' => $character->id,
            'event_id' => $eventA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('resource_origins')->insert([
            'universe_id' => $universe->id,
            'card_id' => $object->id,
            'origin' => 'Forjada',
            'place_card_id' => $place->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('glossary_terms')->insert([
            'universe_id' => $universe->id,
            'language_card_id' => $language->id,
            'term' => 'Ael',
            'meaning' => 'Luz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('character_roles')->insert([
            'novel_id' => $novel->id,
            'card_id' => $character->id,
            'role' => 'protagonista',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('place_containments')->insert([
            'universe_id' => $universe->id,
            'parent_card_id' => $place->id,
            'child_card_id' => Card::create(['universe_id' => $universe->id, 'area' => 'places', 'name' => 'Muelle'])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('card_field_definitions')->insert([
            'universe_id' => $universe->id,
            'key' => 'lealtad',
            'type' => 'number',
            'label' => 'Lealtad',
            'options' => json_encode([]),
            'temporal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $restored = $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
            ])->assertCreated()->json('universe');

        $newNovel = Novel::where('universe_id', $restored['id'])->firstOrFail();
        $newScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn('chapter_id', Chapter::where('novel_id', $newNovel->id)->pluck('id'))->pluck('id'),
        )->firstOrFail();
        $cards = Card::where('universe_id', $restored['id'])->get()->keyBy('name');

        $this->assertDatabaseHas('notes', ['universe_id' => $restored['id'], 'novel_id' => $newNovel->id, 'author_id' => $user->id, 'title' => 'Nota privada']);
        $newNote = DB::table('notes')->where('universe_id', $restored['id'])->where('title', 'Nota privada')->first();
        $this->assertDatabaseHas('note_links', ['note_id' => $newNote->id, 'linkable_type' => 'scene', 'linkable_id' => $newScene->id]);
        $this->assertDatabaseHas('calendar_conversions', ['universe_id' => $restored['id'], 'from_label' => 'Año 1']);
        $this->assertDatabaseHas('event_relations', ['universe_id' => $restored['id'], 'relation' => 'before']);
        $newEvent = DB::table('events')->where('universe_id', $restored['id'])->where('name', 'Fundación')->first();
        $this->assertNotNull($newEvent);
        $this->assertContains($cards['Elena']->id, json_decode($newEvent->participants, true));
        $this->assertContains($cards['Puerto']->id, json_decode($newEvent->places, true));
        $this->assertDatabaseHas('story_changes', ['universe_id' => $restored['id'], 'card_id' => $cards['Elena']->id, 'effective_event_id' => $newEvent->id]);
        $this->assertDatabaseHas('story_contexts', ['universe_id' => $restored['id'], 'scene_id' => $newScene->id, 'card_id' => $cards['Elena']->id]);
        $this->assertDatabaseHas('object_custodies', ['universe_id' => $restored['id'], 'card_id' => $cards['Reliquia']->id, 'holder_card_id' => $cards['Elena']->id]);
        $this->assertDatabaseHas('resource_origins', ['universe_id' => $restored['id'], 'card_id' => $cards['Reliquia']->id, 'place_card_id' => $cards['Puerto']->id]);
        $this->assertDatabaseHas('glossary_terms', ['universe_id' => $restored['id'], 'term' => 'Ael', 'language_card_id' => $cards['Antiguo']->id]);
        $this->assertDatabaseHas('character_roles', ['novel_id' => $newNovel->id, 'card_id' => $cards['Elena']->id, 'role' => 'protagonista']);
        $this->assertDatabaseHas('place_containments', ['universe_id' => $restored['id'], 'parent_card_id' => $cards['Puerto']->id, 'child_card_id' => $cards['Muelle']->id]);
        $this->assertDatabaseHas('card_field_definitions', ['universe_id' => $restored['id'], 'key' => 'lealtad', 'label' => 'Lealtad']);
    }

    public function test_restore_remaps_asset_note_style_and_font_ids_inside_scene_content(): void
    {
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);

        Storage::disk('local')->put('media/mapa.png', $this->pngBytes());
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'kind' => 'image',
            'original_name' => 'mapa.png',
            'mime' => 'image/png',
            'bytes' => strlen($this->pngBytes()),
            'path' => 'media/mapa.png',
        ]);

        Storage::disk('local')->put('fonts/test.ttf', (string) file_get_contents(resource_path('fonts/CrimsonText-Regular.ttf')));
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Crimson',
            'variant' => 'regular',
            'path' => 'fonts/test.ttf',
            'bytes' => (int) filesize(resource_path('fonts/CrimsonText-Regular.ttf')),
            'license' => 'OFL-1.1',
        ]);
        $style = Style::create([
            'novel_id' => $novel->id,
            'name' => 'Énfasis',
            'kind' => 'character',
            'definition' => ['font_id' => $font->id, 'italic' => true],
        ]);

        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'Puente',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [
                    [
                        'id' => 'p1',
                        'type' => 'paragraph',
                        'text' => 'Hola',
                        'marks' => [
                            ['type' => 'character_style', 'start' => 0, 'end' => 4, 'attrs' => ['id' => (string) $style->id]],
                            ['type' => 'footnote_reference', 'start' => 0, 'end' => 4, 'attrs' => ['note_id' => 0]],
                            ['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => ['family' => 'font:'.$font->id]],
                        ],
                    ],
                    ['id' => 'i1', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alt' => 'Mapa']],
                ],
            ],
        ]);
        $note = Footnote::create([
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'kind' => 'foot',
            'number' => 1,
            'text' => 'Nota de la escena.',
        ]);
        $content = $scene->content;
        $content['blocks'][0]['marks'][1]['attrs']['note_id'] = $note->id;
        $scene->forceFill(['content' => $content])->save();

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $restored = $this->asWriter($user)
            ->post('/workspace/backups/restore', [
                'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
            ])->assertCreated()->json('universe');

        $newNovel = Novel::where('universe_id', $restored['id'])->firstOrFail();
        $newScene = Scene::whereIn(
            'chapter_alternative_id',
            ChapterAlternative::whereIn(
                'chapter_id',
                Chapter::where('novel_id', $newNovel->id)->pluck('id'),
            )->pluck('id'),
        )->firstOrFail();
        $newStyle = Style::where('novel_id', $newNovel->id)->firstOrFail();
        $newNote = Footnote::where('novel_id', $newNovel->id)->firstOrFail();
        $newAsset = MediaAsset::where('universe_id', $restored['id'])->firstOrFail();
        $newFont = Font::where('owner_id', $user->id)->where('id', '!=', $font->id)->firstOrFail();

        $this->assertNotSame($style->id, $newStyle->id);
        $this->assertNotSame($note->id, $newNote->id);
        $this->assertNotSame($asset->id, $newAsset->id);
        $this->assertNotSame($font->id, $newFont->id);

        $blocks = $newScene->content['blocks'];
        $marks = $blocks[0]['marks'];

        $this->assertSame((string) $newStyle->id, $marks[0]['attrs']['id']);
        $this->assertSame($newNote->id, $marks[1]['attrs']['note_id']);
        $this->assertSame('font:'.$newFont->id, $marks[2]['attrs']['family']);
        $this->assertSame($newAsset->id, $blocks[1]['attrs']['asset_id']);
    }
}
