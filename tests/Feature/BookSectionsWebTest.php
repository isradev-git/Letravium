<?php

namespace Tests\Feature;

use App\Models\BookSection;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BookSectionsWebTest extends TestCase
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

    public function test_owner_sees_front_and_back_sections_with_catalog_types(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);
        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'front',
            'type' => 'dedication',
            'title' => 'Dedicatoria',
            'content' => 'Para quienes esperan.',
            'position' => 0,
            'include_in_export' => true,
        ]);
        BookSection::create([
            'novel_id' => $novel->id,
            'area' => 'back',
            'type' => 'about_author',
            'title' => 'Sobre la autora',
            'position' => 1,
            'include_in_export' => false,
        ]);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book/sections")
            ->assertOk()
            ->assertSee('Secciones del libro')
            ->assertSee('Dedicatoria')
            ->assertSee('Sobre la autora')
            ->assertSee('name="area"', false)
            ->assertSee('name="type"', false)
            ->assertSee('name="include_in_export"', false);
    }

    public function test_owner_can_create_update_and_delete_a_section(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->post("/workspace/novels/{$novel->id}/book/sections", [
                'area' => 'front',
                'type' => 'dedication',
                'title' => 'Dedicatoria',
                'content' => 'Para quienes esperan.',
                'include_in_export' => 1,
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/sections");

        $section = BookSection::where('novel_id', $novel->id)->firstOrFail();
        $this->assertSame('Dedicatoria', $section->title);
        $this->assertTrue($section->include_in_export);

        $this->asWriter($user)
            ->patch("/workspace/book/sections/{$section->id}", [
                'title' => 'Dedicatoria revisada',
                'content' => 'Para el faro.',
                'include_in_export' => 0,
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/sections");

        $section->refresh();
        $this->assertSame('Dedicatoria revisada', $section->title);
        $this->assertFalse($section->include_in_export);

        $this->asWriter($user)
            ->delete("/workspace/book/sections/{$section->id}")
            ->assertRedirect("/workspace/novels/{$novel->id}/book/sections");

        $this->assertDatabaseMissing('book_sections', ['id' => $section->id]);
    }

    public function test_other_accounts_cannot_open_or_change_sections(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/novels/{$novel->id}/book/sections")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->post("/workspace/novels/{$novel->id}/book/sections", [
                'area' => 'front',
                'type' => 'credits',
            ])
            ->assertNotFound();
    }
}
