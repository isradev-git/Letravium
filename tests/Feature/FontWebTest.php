<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Font;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class FontWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function ttf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fuente.ttf', "\x00\x01\x00\x00".str_repeat('A', 24));
    }

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
    }

    private function upload($user, array $overrides = [])
    {
        return $this->asWriter($user)->post('/workspace/fonts?format=html', array_merge([
            'family' => 'Crimson Text',
            'variant' => 'regular',
            'license' => 'SIL OFL 1.1',
            'file' => $this->ttf(),
        ], $overrides));
    }

    public function test_font_page_lists_only_owner_fonts_with_upload_form(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Crimson Text',
            'variant' => 'regular',
            'license' => 'SIL OFL 1.1',
            'bytes' => 28,
            'path' => 'fonts/'.$user->id.'/crimson.ttf',
            'metadata' => ['format' => 'ttf'],
        ]);
        Font::create([
            'owner_id' => $this->writer()->id,
            'family' => 'Fuente ajena',
            'variant' => 'regular',
            'bytes' => 28,
            'path' => 'fonts/foreign.ttf',
            'metadata' => ['format' => 'ttf'],
        ]);

        $this->asWriter($user)
            ->get('/workspace/fonts')
            ->assertOk()
            ->assertSee('Crimson Text')
            ->assertSee('SIL OFL 1.1')
            ->assertSee('action="/workspace/fonts?format=html"', false)
            ->assertSee('action="/workspace/fonts/'.$font->id.'?format=html"', false)
            ->assertDontSee('Fuente ajena');
    }

    public function test_font_page_shows_a_sample_and_spanish_coverage(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $bytes = (string) file_get_contents(resource_path('fonts/CrimsonText-Regular.ttf'));
        Storage::disk('local')->put('fonts/'.$user->id.'/crimson.ttf', $bytes);
        $font = Font::create([
            'owner_id' => $user->id,
            'family' => 'Crimson Text',
            'variant' => 'Regular',
            'license' => 'SIL OFL 1.1',
            'bytes' => strlen($bytes),
            'path' => 'fonts/'.$user->id.'/crimson.ttf',
            'metadata' => ['format' => 'ttf'],
        ]);

        $this->asWriter($user)
            ->get('/workspace/fonts')
            ->assertOk()
            ->assertSee('font-sample', false)
            ->assertSee("@font-face { font-family: 'priv-".$font->id."'", false)
            ->assertSee('Cobertura completa')
            ->assertSee('El veloz murciélago hindú');
    }

    public function test_html_upload_persists_and_rejects_invalid_files(): void
    {
        Storage::fake('local');
        $user = $this->writer();

        $this->upload($user)->assertRedirect('/workspace/fonts');
        $font = Font::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('Crimson Text', $font->family);
        Storage::disk('local')->assertExists($font->path);

        $this->upload($user, [
            'family' => 'Falsa',
            'file' => UploadedFile::fake()->createWithContent('falsa.ttf', 'no es una fuente'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseMissing('fonts', ['family' => 'Falsa']);
    }

    public function test_unused_font_can_be_deleted(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $this->upload($user);
        $font = Font::where('owner_id', $user->id)->firstOrFail();

        $this->asWriter($user)
            ->delete("/workspace/fonts/{$font->id}?format=html")
            ->assertRedirect('/workspace/fonts');

        $this->assertDatabaseMissing('fonts', ['id' => $font->id]);
        Storage::disk('local')->assertMissing($font->path);
    }

    public function test_dependent_font_is_not_deleted_and_reports_the_dependency(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->upload($user);
        $font = Font::where('owner_id', $user->id)->firstOrFail();
        BookProfile::create([
            'novel_id' => $novel->id,
            'name' => 'Rústica',
            'fonts' => ['body_font_id' => $font->id],
        ]);

        $this->asWriter($user)
            ->delete("/workspace/fonts/{$font->id}?format=html")
            ->assertRedirect('/workspace/fonts')
            ->assertSessionHasErrors('font');

        $this->assertDatabaseHas('fonts', ['id' => $font->id]);
        Storage::disk('local')->assertExists($font->path);
    }

    public function test_foreign_fonts_are_hidden_and_not_deletable(): void
    {
        Storage::fake('local');
        $owner = $this->writer();
        $this->upload($owner);
        $font = Font::where('owner_id', $owner->id)->firstOrFail();
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get('/workspace/fonts')
            ->assertOk()
            ->assertDontSee('Crimson Text');

        $this->asWriter($stranger)
            ->delete("/workspace/fonts/{$font->id}?format=html")
            ->assertNotFound();

        $this->assertDatabaseHas('fonts', ['id' => $font->id]);
    }
}
