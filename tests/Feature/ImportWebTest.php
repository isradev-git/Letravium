<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class ImportWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user, string $name = 'Arven'): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => $name]);
    }

    private function novelFor($user, string $title = 'La carta'): Novel
    {
        return Novel::create([
            'universe_id' => $this->universeFor($user)->id,
            'owner_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function txt(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function previewToken($response): string
    {
        preg_match('/name="token" value="([^"]+)"/', $response->getContent(), $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'The preview page must carry the staged token.');

        return $matches[1];
    }

    public function test_import_screen_lists_only_the_owners_destinations(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $stranger = $this->writer();
        $this->universeFor($stranger, 'Universo ajeno');
        $this->novelFor($stranger, 'Novela ajena');

        $this->asWriter($user)
            ->get('/workspace/imports')
            ->assertOk()
            ->assertSee('Importar manuscrito')
            ->assertSee('Arven')
            ->assertSee($novel->title)
            ->assertDontSee('Universo ajeno')
            ->assertDontSee('Novela ajena')
            ->assertSee('action="/workspace/imports/preview?format=html"', false);
    }

    public function test_txt_preview_renders_editable_chapters_and_commit_form(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $response = $this->asWriter($user)->post('/workspace/imports/preview?format=html', [
            'file' => $this->txt('manuscrito.txt', "Capítulo 1\nEl faro.\nLa carta.\nCapítulo 2\nLa tormenta.\n"),
            'destination' => ['mode' => 'append', 'novel_id' => $novel->id],
        ]);

        $response->assertOk()
            ->assertSee('Capítulo 1')
            ->assertSee('El faro.')
            ->assertSee('La tormenta.')
            ->assertSee('name="chapters[0][title]"', false)
            ->assertSee('name="chapters[0][text]"', false)
            ->assertSee('name="chapters[1][text]"', false)
            ->assertSee('action="/workspace/imports/commit?format=html"', false)
            ->assertSee('name="token"', false);

        $token = $this->previewToken($response);

        $this->assertDatabaseCount('chapters', 0);
        Storage::disk('local')->assertExists("imports/{$user->id}/{$token}/source.txt");
    }

    public function test_html_commit_appends_reviewed_chapters_and_redirects_to_the_editor(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $preview = $this->asWriter($user)->post('/workspace/imports/preview?format=html', [
            'file' => $this->txt('manuscrito.txt', "Capítulo 1\nTexto uno.\n"),
            'destination' => ['mode' => 'append', 'novel_id' => $novel->id],
        ])->assertOk();

        $this->asWriter($user)->post('/workspace/imports/commit?format=html', [
            'token' => $this->previewToken($preview),
            'mode' => 'append',
            'novel_id' => $novel->id,
            'chapters' => [
                ['title' => 'Uno revisado', 'text' => "Primer párrafo.\nSegundo párrafo."],
            ],
        ])->assertRedirect('/workspace/novels/'.$novel->id);

        $this->assertSame('Uno revisado', Chapter::where('novel_id', $novel->id)->firstOrFail()->title);
    }

    public function test_html_commit_creates_a_new_novel_in_the_chosen_universe(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $preview = $this->asWriter($user)->post('/workspace/imports/preview?format=html', [
            'file' => $this->txt('manuscrito.txt', "Capítulo único\nTexto importado.\n"),
            'destination' => ['mode' => 'new_novel', 'universe_id' => $universe->id],
        ])->assertOk()->assertSee('Título de la novela');

        $this->asWriter($user)->post('/workspace/imports/commit?format=html', [
            'token' => $this->previewToken($preview),
            'mode' => 'new_novel',
            'universe_id' => $universe->id,
            'title' => 'Importada',
            'chapters' => [
                ['title' => 'Capítulo único', 'text' => 'Texto importado.'],
            ],
        ])->assertRedirect();

        $novel = Novel::where('universe_id', $universe->id)->where('title', 'Importada')->firstOrFail();
        $this->assertSame(1, $novel->chapters()->count());
    }

    public function test_html_commit_validation_failure_returns_to_a_readable_preview(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $preview = $this->asWriter($user)->post('/workspace/imports/preview?format=html', [
            'file' => $this->txt('manuscrito.txt', "Capítulo uno\nTexto original.\n"),
            'destination' => ['mode' => 'new_novel', 'universe_id' => $universe->id],
        ])->assertOk();

        $token = $this->previewToken($preview);

        $this->asWriter($user)->post('/workspace/imports/commit?format=html', [
            'token' => $token,
            'mode' => 'new_novel',
            'universe_id' => $universe->id,
            'title' => str_repeat('A', 201),
            'chapters' => [['title' => 'Uno', 'text' => 'Texto revisado.']],
        ])->assertRedirect('/workspace/imports/preview/'.$token)
            ->assertSessionHasErrors('title');

        $this->asWriter($user)->get('/workspace/imports/preview/'.$token)
            ->assertOk()
            ->assertSee('Título de la novela')
            ->assertSee('Texto revisado.');
    }

    public function test_preview_rejects_a_foreign_destination(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $foreign = $this->novelFor($this->writer());

        $this->asWriter($user)->post('/workspace/imports/preview?format=html', [
            'file' => $this->txt('manuscrito.txt', "Capítulo 1\nTexto.\n"),
            'destination' => ['mode' => 'append', 'novel_id' => $foreign->id],
        ])->assertNotFound();
    }
}
