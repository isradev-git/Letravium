<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Font;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Universe;
use App\Services\BookPreflight;
use App\Services\FontInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class FontCoverageTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function fontFor($user): Font
    {
        $bytes = (string) file_get_contents(resource_path('fonts/CrimsonText-Regular.ttf'));
        Storage::disk('local')->put('fonts/owner/crimson.ttf', $bytes);

        return Font::create([
            'owner_id' => $user->id,
            'family' => 'Crimson Text',
            'variant' => 'regular',
            'path' => 'fonts/owner/crimson.ttf',
            'bytes' => strlen($bytes),
            'license' => 'SIL OFL 1.1',
            'metadata' => ['format' => 'ttf'],
        ]);
    }

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function sceneFor(Novel $novel, string $text): Scene
    {
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
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
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => $text]],
            ],
        ]);
    }

    public function test_inspector_reads_spanish_coverage_and_reports_a_missing_glyph(): void
    {
        $inspector = new FontInspector;
        $info = $inspector->inspect(resource_path('fonts/CrimsonText-Regular.ttf'));

        $this->assertNotNull($info);
        $this->assertSame(0, $info['fs_type']);
        $this->assertNotContains('á', $inspector->missing('áéíóúüñÁÉÍÓÚÜÑ¿¡«»—…', $info['coverage']));
        $this->assertContains('😀', $inspector->missing('áéíóúüñÁÉÍÓÚÜÑ😀', $info['coverage']));
    }

    public function test_preflight_reports_missing_glyphs_for_a_font_used_by_the_profile(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $font = $this->fontFor($user);
        $this->sceneFor($novel, 'Texto con á y un emoji 😀.');
        BookProfile::create([
            'novel_id' => $novel->id,
            'name' => 'Rústica',
            'fonts' => ['body_font_id' => $font->id],
        ]);

        $result = app(BookPreflight::class)->run($novel);
        $codes = collect($result['blocking'])->pluck('code');

        $this->assertTrue($codes->contains('font.coverage'));
        $this->assertTrue(collect($result['blocking'])->contains(
            fn (array $error): bool => $error['code'] === 'font.coverage'
                && in_array('😀', $error['missing'] ?? [], true),
        ));
    }

    public function test_font_deletion_is_blocked_by_a_profile_and_a_snapshot(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $font = $this->fontFor($user);
        BookProfile::create([
            'novel_id' => $novel->id,
            'name' => 'Rústica',
            'fonts' => ['body_font_id' => $font->id],
        ]);
        Snapshot::create([
            'novel_id' => $novel->id,
            'name' => 'Versión',
            'manifest' => ['fonts' => [['id' => $font->id, 'family' => 'Crimson Text']]],
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->asWriter($user)
            ->deleteJson("/workspace/fonts/{$font->id}")
            ->assertStatus(422)
            ->json();

        $types = collect($response['dependencies'])->pluck('type')->all();
        $this->assertContains('book_profiles', $types);
        $this->assertContains('snapshots', $types);
        $this->assertDatabaseHas('fonts', ['id' => $font->id]);
    }
}
