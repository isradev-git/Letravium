<?php

namespace Tests\Feature;

use App\Models\BookProfile;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BookProfileWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function novelFor($user): Novel
    {
        $universe = Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);

        return Novel::create([
            'universe_id' => $universe->id,
            'owner_id' => $user->id,
            'title' => 'La carta',
        ]);
    }

    public function test_owner_can_create_and_see_book_profiles_from_the_browser(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/book/profiles", [
                'name' => 'Rústica 148x210',
                'page_size' => '148x210mm',
                'orientation' => 'portrait',
                'margins' => ['top' => 18, 'bottom' => 22, 'left' => 16, 'right' => 16],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/profiles");

        $this->assertDatabaseHas('book_profiles', [
            'novel_id' => $novel->id,
            'name' => 'Rústica 148x210',
            'page_size' => '148x210mm',
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book/profiles")
            ->assertOk()
            ->assertSee('Perfiles editoriales')
            ->assertSee('Rústica 148x210')
            ->assertSee('name="page_size"', false)
            ->assertSee('name="margins[top]"', false);
    }

    public function test_owner_can_edit_a_book_profile_from_the_browser(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        $profile = BookProfile::create([
            'novel_id' => $novel->id,
            'name' => 'Rústica',
            'page_size' => '148x210mm',
        ]);

        $this->asWriter($user)
            ->patch("/workspace/book/profiles/{$profile->id}", [
                'name' => 'Rústica revisada',
                'page_size' => '150x210mm',
                'orientation' => 'landscape',
                'margins' => ['top' => 20, 'bottom' => 24, 'left' => 15, 'right' => 15],
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/profiles");

        $profile->refresh();
        $this->assertSame('Rústica revisada', $profile->name);
        $this->assertSame('150x210mm', $profile->page_size);
        $this->assertSame('landscape', $profile->orientation);
        $this->assertSame(15, $profile->margins['left']);
    }

    public function test_other_accounts_cannot_open_or_create_book_profiles(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/novels/{$novel->id}/book/profiles")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->post("/workspace/novels/{$novel->id}/book/profiles", ['name' => 'Ajena'])
            ->assertNotFound();
    }
}
