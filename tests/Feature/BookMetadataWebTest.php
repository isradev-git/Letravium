<?php

namespace Tests\Feature;

use App\Models\BookMetadata;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class BookMetadataWebTest extends TestCase
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

    public function test_owner_sees_metadata_defaults_and_can_save_them(): void
    {
        $user = $this->writer();
        $novel = $this->novelFor($user);

        $this->asWriter($user)
            ->get("/workspace/novels/{$novel->id}/book/metadata")
            ->assertOk()
            ->assertSee('Metadatos del libro')
            ->assertSee('La carta')
            ->assertSee('name="language"', false)
            ->assertSee('name="isbn"', false);

        $this->asWriter($user)
            ->put("/workspace/novels/{$novel->id}/book/metadata", [
                'title' => 'La carta del faro',
                'subtitle' => 'Una historia de invierno',
                'author' => 'Elena Valdara',
                'language' => 'es',
                'description' => 'Una novela sobre la memoria.',
                'rights' => 'Todos los derechos reservados.',
                'edition' => 'Primera',
                'publisher' => 'Editorial Arven',
                'isbn' => '978-84-0000000-0-0',
            ])
            ->assertRedirect("/workspace/novels/{$novel->id}/book/metadata");

        $metadata = BookMetadata::where('novel_id', $novel->id)->firstOrFail();
        $this->assertSame('La carta del faro', $metadata->title);
        $this->assertSame('Elena Valdara', $metadata->author);
        $this->assertSame('978-84-0000000-0-0', $metadata->isbn);
    }

    public function test_other_accounts_cannot_open_or_update_metadata(): void
    {
        $owner = $this->writer();
        $novel = $this->novelFor($owner);
        $stranger = $this->writer();

        $this->asWriter($stranger)
            ->get("/workspace/novels/{$novel->id}/book/metadata")
            ->assertNotFound();

        $this->asWriter($stranger)
            ->put("/workspace/novels/{$novel->id}/book/metadata", ['title' => 'Ajena'])
            ->assertNotFound();
    }
}
