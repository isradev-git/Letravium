<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class SnapshotCompareWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0, 'revision' => 1]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'revision' => 1,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto original del faro.']],
            ],
        ]);

        return [$novel, $chapter, $scene];
    }

    public function test_owner_sees_a_readable_snapshot_comparison(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene] = $this->novelFor($user);

        $snapshot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Antes de revisar'])
            ->assertCreated()
            ->json('snapshot');

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto cambiado del faro.']],
            ],
            'revision' => 2,
        ])->save();
        $chapter->forceFill(['revision' => 2])->save();

        $this->asWriter($user)
            ->get("/workspace/snapshots/{$snapshot['id']}/compare")
            ->assertOk()
            ->assertSee('Comparar versión')
            ->assertSee('Antes de revisar')
            ->assertSee('Modificada')
            ->assertSee('content')
            ->assertSee('Texto original del faro.')
            ->assertSee('Texto cambiado del faro.')
            ->assertSee('Antes')
            ->assertSee('Ahora')
            ->assertSee('Restaurar este fragmento');

        $this->asWriter($this->writer())
            ->get("/workspace/snapshots/{$snapshot['id']}/compare")
            ->assertNotFound();
    }

    public function test_owner_can_restore_a_modified_fragment_from_the_comparison(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene] = $this->novelFor($user);

        $snapshot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Antes de revisar'])
            ->assertCreated()
            ->json('snapshot');

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto cambiado del faro.']],
            ],
            'revision' => 2,
        ])->save();

        $this->asWriter($user)
            ->post("/workspace/snapshots/{$snapshot['id']}/restore-fragment?format=html", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'expected_revision' => 2,
                'confirm' => 1,
            ])
            ->assertRedirect("/workspace/snapshots/{$snapshot['id']}/compare");

        $this->assertSame('Texto original del faro.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_stale_fragment_restore_reports_an_error_without_writing(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene] = $this->novelFor($user);

        $snapshot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Antes de revisar'])
            ->assertCreated()
            ->json('snapshot');

        $scene->forceFill([
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto cambiado del faro.']],
            ],
            'revision' => 2,
        ])->save();

        $this->asWriter($user)
            ->post("/workspace/snapshots/{$snapshot['id']}/restore-fragment?format=html", [
                'resource_type' => 'scene',
                'resource_id' => $scene->id,
                'expected_revision' => 1,
                'confirm' => 1,
            ])
            ->assertRedirect("/workspace/snapshots/{$snapshot['id']}/compare")
            ->assertSessionHasErrors('restore');

        $this->assertSame('Texto cambiado del faro.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_history_screen_links_to_the_comparison(): void
    {
        $user = $this->writer();
        [$novel] = $this->novelFor($user);

        $snapshot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Versión uno'])
            ->assertCreated()
            ->json('snapshot');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/snapshots")
            ->assertOk()
            ->assertSee("/workspace/snapshots/{$snapshot['id']}/compare", false);
    }
}
