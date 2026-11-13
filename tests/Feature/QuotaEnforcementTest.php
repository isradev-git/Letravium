<?php

namespace Tests\Feature;

use App\Models\AccountQuota;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class QuotaEnforcementTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function limit(User $user, int $bytes): void
    {
        AccountQuota::updateOrCreate(['user_id' => $user->id], ['bytes_limit' => $bytes]);
    }

    private function universeFor(User $user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function novelFor(User $user): Novel
    {
        return Novel::create([
            'universe_id' => $this->universeFor($user)->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    private function ttfBytes(): string
    {
        return "\x00\x01\x00\x00".str_repeat('A', 24);
    }

    private function uploadImage(User $user, Novel $novel, string $bytes)
    {
        return $this->asWriter($user)->post('/workspace/assets', [
            'kind' => 'image',
            'novel_id' => $novel->id,
            'file' => UploadedFile::fake()->createWithContent('imagen.png', $bytes),
        ], ['Accept' => 'application/json']);
    }

    public function test_image_upload_is_rejected_when_it_exceeds_the_owner_quota(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->limit($user, 10);

        $this->uploadImage($user, $novel, $this->pngBytes())
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_font_upload_is_rejected_when_it_exceeds_the_owner_quota(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $this->limit($user, 10);

        $this->asWriter($user)->post('/workspace/fonts', [
            'family' => 'Prueba',
            'variant' => 'regular',
            'file' => UploadedFile::fake()->createWithContent('fuente.ttf', $this->ttfBytes()),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('fonts', 0);
    }

    public function test_import_preview_is_rejected_when_it_exceeds_the_owner_quota(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $this->limit($user, 10);

        $this->asWriter($user)->post('/workspace/imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('libro.txt', str_repeat('A', 40)),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles("imports/{$user->id}"));
    }

    public function test_deleting_an_asset_frees_quota_and_updates_bytes_used(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $bytes = $this->pngBytes();
        $this->limit($user, 1000);

        $asset = $this->uploadImage($user, $novel, $bytes)
            ->assertCreated()
            ->json('asset');

        $this->assertDatabaseHas('account_quotas', [
            'user_id' => $user->id,
            'bytes_used' => strlen($bytes),
        ]);

        $this->asWriter($user)
            ->deleteJson("/workspace/assets/{$asset['id']}")
            ->assertOk();

        $this->assertDatabaseHas('account_quotas', [
            'user_id' => $user->id,
            'bytes_used' => 0,
        ]);
    }

    public function test_full_quota_still_allows_reading_and_export_screen(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $bytes = $this->pngBytes();
        $this->limit($user, strlen($bytes));

        $this->uploadImage($user, $novel, $bytes)->assertCreated();

        $this->asWriter($user)
            ->getJson("/workspace/novels/{$novel->id}/chapters")
            ->assertOk();

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book")
            ->assertOk();
    }

    public function test_import_preview_counts_the_source_and_preview_against_the_quota(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $this->limit($user, 20);

        $this->asWriter($user)->post('/workspace/imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('texto.txt', 'Hola mundo'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles("imports/{$user->id}"));
    }

    public function test_backup_restore_is_rejected_when_the_package_exceeds_the_owner_quota(): void
    {
        Storage::fake('local');
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $this->limit($user, 100000);

        $this->uploadImage($user, $novel, $this->pngBytes())->assertCreated();

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $novel->universe_id])
            ->assertOk();

        $this->limit($user, 1);

        $this->asWriter($user)->post('/workspace/backups/restore', [
            'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('universes', 1);
    }
}
