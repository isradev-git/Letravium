<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Comment;
use App\Models\CorrectionProposal;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CorrectionWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function fixture($user): array
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
        $novel = Novel::create(['universe_id' => $universe->id, 'owner_id' => $user->id, 'title' => 'La carta']);
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0, 'revision' => 1]);
        $alternative = ChapterAlternative::create(['chapter_id' => $chapter->id, 'alias' => 'Principal', 'is_active' => true]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'revision' => 1,
            'content' => ['schema_version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto original.']]],
        ]);
        $comment = Comment::create([
            'novel_id' => $novel->id,
            'chapter_id' => $chapter->id,
            'scene_id' => $scene->id,
            'block_id' => 'b1',
            'quoted_text' => 'Texto original.',
            'status' => 'open',
            'author_id' => $user->id,
        ]);
        $comment->messages()->create(['author_id' => $user->id, 'body' => 'Corregir esta frase.']);
        $proposal = CorrectionProposal::create([
            'comment_id' => $comment->id,
            'base_revision' => 1,
            'original' => 'Texto original.',
            'proposed' => 'Texto corregido.',
            'status' => CorrectionProposal::PENDING,
        ]);

        return [$novel, $chapter, $scene, $comment, $proposal];
    }

    public function test_owner_sees_correction_proposals_on_the_comment_screen(): void
    {
        $user = $this->writer();
        [$novel] = $this->fixture($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->assertSee('Correcciones')
            ->assertSee('Texto original.')
            ->assertSee('Texto corregido.')
            ->assertSee('Aceptar corrección')
            ->assertSee('Rechazar corrección');
    }

    public function test_owner_can_reject_a_correction_from_the_browser(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene, $comment, $proposal] = $this->fixture($user);

        $this->asWriter($user)
            ->post("/workspace/corrections/{$proposal->id}/reject?format=html")
            ->assertRedirect("/workspace/novels/{$novel->id}/comments");

        $this->assertSame(CorrectionProposal::REJECTED, $proposal->fresh()->status);
        $this->assertSame('Texto original.', $scene->fresh()->content['blocks'][0]['text']);
    }

    public function test_owner_can_accept_a_correction_with_a_chapter_lease(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene, $comment, $proposal] = $this->fixture($user);
        EditLease::create([
            'resource_type' => 'chapter',
            'resource_id' => $chapter->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->asWriter($user)
            ->post("/workspace/corrections/{$proposal->id}/accept?format=html")
            ->assertRedirect("/workspace/novels/{$novel->id}/comments");

        $this->assertSame('Texto corregido.', $scene->fresh()->content['blocks'][0]['text']);
        $this->assertSame(CorrectionProposal::ACCEPTED, $proposal->fresh()->status);
        $this->assertSame('resolved', $comment->fresh()->status);
    }

    public function test_stale_correction_is_rejected_without_writing(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene, $comment, $proposal] = $this->fixture($user);
        $scene->forceFill(['revision' => 2])->save();
        EditLease::create([
            'resource_type' => 'chapter',
            'resource_id' => $chapter->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->asWriter($user)
            ->post("/workspace/corrections/{$proposal->id}/accept?format=html")
            ->assertRedirect("/workspace/novels/{$novel->id}/comments")
            ->assertSessionHasErrors('correction');

        $this->assertSame('Texto original.', $scene->fresh()->content['blocks'][0]['text']);
        $this->assertSame(CorrectionProposal::STALE, $proposal->fresh()->status);
    }
}
