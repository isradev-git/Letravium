<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class CardFieldsWebTest extends TestCase
{
    use ActsAsWriter, RefreshDatabase;

    private function universeFor($user): Universe
    {
        return Universe::create(['owner_id' => $user->id, 'name' => 'Arven']);
    }

    private function field(Universe $universe, array $overrides = []): int
    {
        return DB::table('card_field_definitions')->insertGetId(array_merge([
            'universe_id' => $universe->id,
            'key' => 'language',
            'type' => 'text',
            'label' => 'Idioma',
            'options' => json_encode([]),
            'temporal' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_page_lists_owner_fields_and_hides_foreign_universes(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $this->field($universe);

        $this->asWriter($user)
            ->get("/workspace/universes/{$universe->id}/card-fields")
            ->assertOk()
            ->assertSee('Campos personalizados')
            ->assertSee('Idioma')
            ->assertSee('language')
            ->assertSee('action="/workspace/universes/'.$universe->id.'/card-fields?format=html"', false);

        $this->asWriter($this->writer())
            ->get("/workspace/universes/{$universe->id}/card-fields")
            ->assertNotFound();
    }

    public function test_html_create_redirects_and_persists_options(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);

        $this->asWriter($user)
            ->post("/workspace/universes/{$universe->id}/card-fields?format=html", [
                'key' => 'language',
                'type' => 'select',
                'label' => 'Idioma',
                'options_text' => 'Común, Antiguo, Ritual',
                'temporal' => '1',
            ])
            ->assertRedirect("/workspace/universes/{$universe->id}/card-fields");

        $field = DB::table('card_field_definitions')->where('universe_id', $universe->id)->first();
        $this->assertSame('language', $field->key);
        $this->assertSame('select', $field->type);
        $this->assertSame(['Común', 'Antiguo', 'Ritual'], json_decode($field->options, true));
        $this->assertTrue((bool) $field->temporal);
    }

    public function test_card_form_renders_and_clears_custom_fields(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $this->field($universe, ['key' => 'language', 'label' => 'Idioma']);
        $card = Card::create(['universe_id' => $universe->id, 'area' => 'characters', 'name' => 'Elena']);

        $this->asWriter($user)
            ->get("/workspace/cards/{$card->id}")
            ->assertOk()
            ->assertSee('Idioma')
            ->assertSee('name="fields[language]"', false)
            ->assertSee('name="present_fields[]" value="language"', false);

        $this->asWriter($user)
            ->patch("/workspace/cards/{$card->id}", [
                'name' => 'Elena',
                'fields' => ['language' => 'Antiguo'],
                'present_fields' => ['language'],
            ])
            ->assertRedirect("/workspace/cards/{$card->id}");

        $this->assertSame('Antiguo', $card->refresh()->fields['language']);

        $this->asWriter($user)
            ->patch("/workspace/cards/{$card->id}", [
                'name' => 'Elena',
                'present_fields' => ['language'],
            ])
            ->assertRedirect("/workspace/cards/{$card->id}");

        $this->assertNull($card->refresh()->fields['language']);
    }

    public function test_html_delete_requires_confirmation_and_preserves_values(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $fieldId = $this->field($universe);
        $card = Card::create([
            'universe_id' => $universe->id,
            'area' => 'characters',
            'name' => 'Elena',
            'fields' => ['language' => 'Antiguo'],
        ]);

        $this->asWriter($user)
            ->delete("/workspace/card-fields/{$fieldId}?format=html")
            ->assertRedirect("/workspace/universes/{$universe->id}/card-fields")
            ->assertSessionHasErrors('field');

        $this->assertNull(DB::table('card_field_definitions')->where('id', $fieldId)->first()->removed_at);

        $this->asWriter($user)
            ->delete("/workspace/card-fields/{$fieldId}?format=html", ['confirm' => '1'])
            ->assertRedirect("/workspace/universes/{$universe->id}/card-fields");

        $removed = DB::table('card_field_definitions')->where('id', $fieldId)->first();
        $this->assertNotNull($removed->removed_at);
        $this->assertSame(['Antiguo'], array_values(json_decode($removed->removed_values, true)));
        $this->assertSame(['language' => 'Antiguo'], $card->refresh()->fields);
    }

    public function test_json_contracts_are_unchanged(): void
    {
        $user = $this->writer();
        $universe = $this->universeFor($user);
        $this->field($universe);

        $this->asWriter($user)
            ->getJson("/workspace/universes/{$universe->id}/card-fields")
            ->assertOk()
            ->assertJsonPath('fields.0.key', 'language');
    }
}
