<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Comment;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CommentWebTest extends TestCase
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
        $chapter = Chapter::create(['novel_id' => $novel->id, 'title' => 'Uno', 'position' => 0]);
        $alternative = ChapterAlternative::create([
            'chapter_id' => $chapter->id,
            'alias' => 'Principal',
            'is_active' => true,
        ]);
        $scene = Scene::create([
            'chapter_alternative_id' => $alternative->id,
            'title' => 'El faro',
            'position' => 0,
            'content' => [
                'schema_version' => 1,
                'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Texto comentado.']],
            ],
        ]);

        return [$novel, $chapter, $scene];
    }

    public function test_owner_can_review_reply_and_resolve_a_comment(): void
    {
        $user = $this->writer();
        [$novel, $chapter, $scene] = $this->novelFor($user);

        $comment = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/comments", [
                'chapter_id' => $chapter->id,
                'scene_id' => $scene->id,
                'block_id' => 'b1',
                'quoted_text' => 'Texto',
                'body' => 'Revisar esta frase.',
            ])
            ->assertCreated()
            ->json('comment');

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/comments")
            ->assertOk()
            ->assertSee('Comentarios')
            ->assertSee('Revisar esta frase.')
            ->assertSee('Abierto')
            ->assertSee('Responder')
            ->assertSee('name="status"', false);

        $this->asWriter($user)
            ->post("/workspace/comments/{$comment['id']}/messages", [
                'body' => 'Respuesta del autor.',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/comments");

        $this->assertDatabaseHas('comment_messages', [
            'comment_id' => $comment['id'],
            'body' => 'Respuesta del autor.',
        ]);

        $this->asWriter($user)
            ->patch("/workspace/comments/{$comment['id']}", ['status' => 'resolved'])
            ->assertRedirect("/workspace/novels/{$novel->id}/comments");

        $this->assertSame('resolved', Comment::findOrFail($comment['id'])->status);
    }

    public function test_other_accounts_cannot_open_the_comment_screen(): void
    {
        $owner = $this->writer();
        [$novel] = $this->novelFor($owner);

        $this->asWriter($this->writer())
            ->get("/workspace/novels/{$novel->id}/comments")
            ->assertNotFound();
    }
}
