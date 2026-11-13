<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class DocumentsWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function library($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);

        return [$universe, $novel];
    }

    private function pdf(string $name = 'dossier.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    public function test_owner_sees_universe_documents_and_upload_form(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        [$universe] = $this->library($user);
        MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'kind' => 'attachment',
            'original_name' => 'mapa.pdf',
            'mime' => 'application/pdf',
            'bytes' => 10,
            'path' => 'media/'.$user->id.'/mapa.pdf',
            'metadata' => ['format' => 'pdf'],
        ]);
        $stranger = $this->writer();
        $foreignUniverse = Universe::create(['owner_id' => $stranger->id, 'name' => 'Reino ajeno']);
        MediaAsset::create([
            'owner_id' => $stranger->id,
            'universe_id' => $foreignUniverse->id,
            'kind' => 'attachment',
            'original_name' => 'ajeno.pdf',
            'mime' => 'application/pdf',
            'bytes' => 10,
            'path' => 'media/foreign/ajeno.pdf',
            'metadata' => ['format' => 'pdf'],
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/documents")
            ->assertOk()
            ->assertSee('Documentos')
            ->assertSee('mapa.pdf')
            ->assertSee('action="/workspace/assets?format=html"', false)
            ->assertDontSee('ajeno.pdf');
    }

    public function test_html_upload_to_universe_and_novel_redirects_and_persists(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        [$universe, $novel] = $this->library($user);

        $this->asWriter($user)
            ->post('/workspace/assets?format=html', [
                'kind' => 'attachment',
                'universe_id' => $universe->id,
                'file' => $this->pdf(),
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/documents");

        $this->assertDatabaseHas('media_assets', [
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'kind' => 'attachment',
            'original_name' => 'dossier.pdf',
        ]);

        $this->asWriter($user)
            ->post('/workspace/assets?format=html', [
                'kind' => 'attachment',
                'universe_id' => $universe->id,
                'novel_id' => $novel->id,
                'file' => $this->pdf('notas.pdf'),
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/documents");

        $this->assertDatabaseHas('media_assets', [
            'novel_id' => $novel->id,
            'original_name' => 'notas.pdf',
        ]);
    }

    public function test_invalid_attachment_is_rejected_without_a_row(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        [$universe] = $this->library($user);

        $this->asWriter($user)
            ->post('/workspace/assets?format=html', [
                'kind' => 'attachment',
                'universe_id' => $universe->id,
                'file' => UploadedFile::fake()->createWithContent('programa.exe', 'MZ'.str_repeat('A', 32)),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_txt_attachment_preview_is_escaped_and_delete_works(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        [$universe] = $this->library($user);
        Storage::disk('local')->put('media/'.$user->id.'/notas.txt', "Notas del faro\n<script>alert('x')</script>");
        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $universe->id,
            'kind' => 'attachment',
            'original_name' => 'notas.txt',
            'mime' => 'text/plain',
            'bytes' => 50,
            'path' => 'media/'.$user->id.'/notas.txt',
            'metadata' => ['format' => 'txt'],
        ]);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/documents")
            ->assertOk()
            ->assertSee('Notas del faro')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert', false);

        $this->asWriter($user)
            ->delete("/workspace/assets/{$asset->id}?format=html")
            ->assertRedirect("/workspace/universes/{$universe->id}/documents");

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertMissing('media/'.$user->id.'/notas.txt');
    }

    public function test_foreign_accounts_cannot_open_or_delete_documents(): void
    {
        Storage::fake('local');
        $owner = $this->writer();
        [$universe] = $this->library($owner);
        $this->asWriter($owner)->post('/workspace/assets?format=html', [
            'kind' => 'attachment',
            'universe_id' => $universe->id,
            'file' => $this->pdf(),
        ]);
        $asset = MediaAsset::where('owner_id', $owner->id)->firstOrFail();
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/universes/{$universe->id}/documents")
            ->assertNotFound();
        $this->asWriter($stranger)
            ->delete("/workspace/assets/{$asset->id}?format=html")
            ->assertNotFound();

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }

    public function test_json_store_and_index_contracts_are_unchanged(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        [$universe] = $this->library($user);

        $this->asWriter($user)
            ->postJson('/workspace/assets', [
                'kind' => 'attachment',
                'universe_id' => $universe->id,
                'file' => $this->pdf(),
            ])
            ->assertCreated()
            ->assertJsonPath('asset.kind', 'attachment');

        $this->asWriter($user)
            ->getJson('/workspace/assets')
            ->assertOk()
            ->assertJsonCount(1, 'assets');
    }
}
