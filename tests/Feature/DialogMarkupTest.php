<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DialogMarkupTest extends TestCase
{
    public function test_dialog_component_exposes_accessible_markup_and_fallback(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-dialog id="new-novel" title="Nueva novela" description="Empieza con un título." fallback="/workspace?form=new-novel">
    <form method="POST" action="/workspace/universes/1/novels">
        <label for="new-novel-title">Título</label>
        <input id="new-novel-title" name="title" autofocus>
        <p data-dialog-error>El título es obligatorio.</p>
        <div class="app-dialog-actions">
            <button type="button" data-dialog-close>Cancelar</button>
            <button type="submit">Crear novela</button>
        </div>
    </form>
</x-dialog>
BLADE);

        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('id="new-novel"', $html);
        $this->assertStringContainsString('aria-labelledby="new-novel-title"', $html);
        $this->assertStringContainsString('aria-describedby="new-novel-description"', $html);
        $this->assertStringContainsString('data-dialog-close', $html);
        $this->assertStringContainsString('data-dialog-error', $html);
        $this->assertStringContainsString('href="/workspace?form=new-novel"', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }
}
