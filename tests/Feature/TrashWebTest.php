<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class TrashWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function chapterFor($user): Chapter
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);

        return Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
    }

    public function test_trash_page_lists_restores_and_permanently_deletes_with_confirmation(): void
    {
        $user = $this->writer();
        $chapter = $this->chapterFor($user);

        $firstEntry = $this->asWriter($user)
            ->postJson("/workspace/chapter/{$chapter->id}/trash")
            ->assertStatus(201)
            ->json('trash_entry.id');

        $this->asWriter($user)
            ->get('/workspace/trash')
            ->assertOk()
            ->assertSee('Papelera')
            ->assertSee('Uno')
            ->assertSee('/workspace/trash/'.$firstEntry.'/restore', false);

        $this->asWriter($user)
            ->post('/workspace/trash/'.$firstEntry.'/restore')
            ->assertRedirect('/workspace/trash');

        $this->assertNotNull(Chapter::find($chapter->id));
        $this->assertDatabaseMissing('trash_entries', ['id' => $firstEntry]);

        $secondEntry = $this->asWriter($user)
            ->postJson("/workspace/chapter/{$chapter->id}/trash")
            ->assertStatus(201)
            ->json('trash_entry.id');

        $this->asWriter($user)
            ->delete('/workspace/trash/'.$secondEntry, ['confirmation' => 'Uno'])
            ->assertRedirect('/workspace/trash');

        $this->assertNull(Chapter::withoutGlobalScope('trashed')->find($chapter->id));
    }
}
