<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SnapshotWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
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
        Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'El faro.']],
            ],
        ]);

        return $novel;
    }

    public function test_owner_can_create_and_see_a_snapshot_from_the_history_screen(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Versión inicial'])
            ->assertRedirect("/workspace/novels/{$novel->id}/snapshots");

        $this->assertDatabaseHas('snapshots', ['novel_id' => $novel->id, 'name' => 'Versión inicial']);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/snapshots")
            ->assertOk()
            ->assertSee('Historial de versiones')
            ->assertSee('Versión inicial')
            ->assertSee('name="name"', false)
            ->assertSee("/workspace/novels/{$novel->id}/snapshots", false);
    }

    public function test_owner_can_rename_a_snapshot_from_the_history_screen(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Versión inicial'])
            ->assertRedirect("/workspace/novels/{$novel->id}/snapshots");

        $snapshot = Snapshot::where('novel_id', $novel->id)->firstOrFail();

        $this->asWriter($user)
            ->patch("/workspace/snapshots/{$snapshot->id}", ['name' => 'Antes de la revisión'])
            ->assertRedirect("/workspace/novels/{$novel->id}/snapshots");

        $this->assertSame('Antes de la revisión', $snapshot->fresh()->name);
    }

    public function test_other_accounts_cannot_open_or_create_snapshots(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/novels/{$novel->id}/snapshots")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->post("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Ajena'])
            ->assertNotFound();

        $this->assertDatabaseMissing('snapshots', ['novel_id' => $novel->id]);
    }
}
