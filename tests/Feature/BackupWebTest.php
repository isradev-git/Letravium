<?php

namespace Tests\Feature;

use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BackupWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user, string $name = 'Arven'): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => $name]);
    }

    public function test_backup_screen_lists_only_the_owners_universes(): void
    {
        $user = $this->writer();
        $this->universeFor($user, 'Arven');
        $this->universeFor($this->writer(), 'Universo ajeno');

        $this->asWriter($user)
            ->get('/workspace/backups')
            ->assertOk()
            ->assertSee('Copias de seguridad')
            ->assertSee('Arven')
            ->assertDontSee('Universo ajeno')
            ->assertSee('action="/workspace/backups/export"', false)
            ->assertSee('action="/workspace/backups/restore?format=html"', false);
    }

    public function test_html_restore_creates_a_new_private_universe_and_redirects(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $export = $this->asWriter($user)
            ->postJson('/workspace/backups/export', ['universe_id' => $universe->id])
            ->assertOk();

        $this->asWriter($user)
            ->post('/workspace/backups/restore?format=html', [
                'file' => new UploadedFile($export->getFile()->getPathname(), 'copia.zip', 'application/zip', null, true),
            ])
            ->assertRedirect('/workspace');

        $this->assertDatabaseCount('universes', 2);
        $this->assertDatabaseHas('universes', ['owner_id' => $user->id]);
    }

    public function test_html_restore_rejects_an_invalid_package_without_writing(): void
    {
        $user = $this->writer();
        $this->universeFor($user);

        $this->asWriter($user)
            ->from('/workspace/backups')
            ->post('/workspace/backups/restore?format=html', [
                'file' => UploadedFile::fake()->createWithContent('copia.zip', 'no es un zip'),
            ])
            ->assertRedirect('/workspace/backups')
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('universes', 1);
    }
}
