<?php

namespace Tests\Feature;

use App\Models\BetaReader;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BetaReaderWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function snapshotFor($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);

        $snapshot = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/snapshots", ['name' => 'Borrador beta'])
            ->assertCreated()
            ->json('snapshot');

        return [$novel, Snapshot::findOrFail($snapshot['id'])];
    }

    public function test_owner_can_invite_a_beta_reader_and_see_the_share_link(): void
    {
        $user = $this->writer();
        [$novel, $snapshot] = $this->snapshotFor($user);

        $this->asWriter($user)
            ->get("/workspace/snapshots/{$snapshot->id}/beta-readers")
            ->assertOk()
            ->assertSee('Lectores beta')
            ->assertSee('Todavía no hay lectores')
            ->assertSee('name="email"', false);

        $this->asWriter($user)
            ->post("/workspace/snapshots/{$snapshot->id}/beta-readers", [
                'email' => 'lectora@example.test',
                'can_comment' => 1,
            ])
            ->assertRedirect("/workspace/snapshots/{$snapshot->id}/beta-readers");

        $reader = BetaReader::where('snapshot_id', $snapshot->id)->firstOrFail();
        $this->assertSame('lectora@example.test', $reader->email);
        $this->assertTrue($reader->can_comment);

        $this->asWriter($user)
            ->get("/workspace/snapshots/{$snapshot->id}/beta-readers")
            ->assertOk()
            ->assertSee('lectora@example.test')
            ->assertSee("/workspace/beta/{$reader->token}", false);
    }

    public function test_owner_can_update_scope_and_revoke_a_reader(): void
    {
        $user = $this->writer();
        [$novel, $snapshot] = $this->snapshotFor($user);
        $one = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $two = Chapter::create(['novel_id' => $novel->id, 'title' => 'Dos', 'position' => 1]);

        $this->asWriter($user)
            ->post("/workspace/snapshots/{$snapshot->id}/beta-readers", [
                'email' => 'lectora@example.test',
                'all_chapters' => 0,
                'scope' => ['chapters' => [$one->id]],
            ])
            ->assertRedirect("/workspace/snapshots/{$snapshot->id}/beta-readers");

        $reader = BetaReader::where('snapshot_id', $snapshot->id)->firstOrFail();
        $this->assertSame([$one->id], $reader->scope['chapters']);

        $this->asWriter($user)
            ->patch("/workspace/beta-readers/{$reader->id}", [
                'expires_at' => now()->addDays(10)->format('Y-m-d'),
                'can_comment' => 0,
                'all_chapters' => 0,
                'scope' => ['chapters' => [$two->id]],
            ])
            ->assertRedirect("/workspace/snapshots/{$snapshot->id}/beta-readers");

        $reader->refresh();
        $this->assertSame([$two->id], $reader->scope['chapters']);
        $this->assertFalse($reader->can_comment);

        $this->asWriter($user)
            ->delete("/workspace/beta-readers/{$reader->id}")
            ->assertRedirect("/workspace/snapshots/{$snapshot->id}/beta-readers");

        $this->assertDatabaseMissing('beta_readers', ['id' => $reader->id]);
    }

    public function test_scope_update_rejects_chapters_from_another_novel(): void
    {
        $user = $this->writer();
        [$novel, $snapshot] = $this->snapshotFor($user);
        $reader = BetaReader::create([
            'snapshot_id' => $snapshot->id,
            'email' => 'lectora@example.test',
            'token' => str_repeat('a', 64),
            'expires_at' => now()->addDays(30),
            'can_comment' => true,
        ]);
        $other = Novel::create(['universe_id' => $novel->universe_id, 'owner_id' => $user->id, 'title' => 'Otra']);
        $foreignChapter = Chapter::create(['novel_id' => $other->id, 'title' => 'Ajena', 'position' => 0]);

        $this->asWriter($user)
            ->patch("/workspace/beta-readers/{$reader->id}", [
                'all_chapters' => 0,
                'scope' => ['chapters' => [$foreignChapter->id]],
            ])
            ->assertStatus(422);

        $this->assertNull($reader->fresh()->scope);
    }

    public function test_other_accounts_cannot_update_or_revoke_a_reader(): void
    {
        $owner = $this->writer();
        [$novel, $snapshot] = $this->snapshotFor($owner);
        $reader = BetaReader::create([
            'snapshot_id' => $snapshot->id,
            'email' => 'lectora@example.test',
            'token' => str_repeat('b', 64),
            'expires_at' => now()->addDays(30),
            'can_comment' => true,
        ]);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->patch("/workspace/beta-readers/{$reader->id}", ['can_comment' => 0])
            ->assertNotFound();

        $this->asWriter($stranger)
            ->delete("/workspace/beta-readers/{$reader->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('beta_readers', ['id' => $reader->id]);
    }

    public function test_beta_reader_invitation_requires_a_contact(): void
    {
        $user = $this->writer();
        [, $snapshot] = $this->snapshotFor($user);

        $this->asWriter($user)
            ->post("/workspace/snapshots/{$snapshot->id}/beta-readers", [])
            ->assertStatus(422);

        $this->assertSame(0, BetaReader::where('snapshot_id', $snapshot->id)->count());
    }

    public function test_other_accounts_cannot_open_or_invite_beta_readers(): void
    {
        $owner = $this->writer();
        [, $snapshot] = $this->snapshotFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/snapshots/{$snapshot->id}/beta-readers")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->post("/workspace/snapshots/{$snapshot->id}/beta-readers", ['email' => 'ajena@example.test'])
            ->assertNotFound();
    }
}
