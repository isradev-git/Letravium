<?php

namespace Tests\Unit;

use App\Services\DocumentHtml;
use PHPUnit\Framework\TestCase;

class DocumentHtmlTest extends TestCase
{
    public function test_it_renders_escaped_text_with_mark_ranges(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Hola <mundo>',
                'marks' => [
                    ['type' => 'bold', 'start' => 0, 'end' => 4],
                    ['type' => 'italic', 'start' => 5, 'end' => 12],
                ],
            ]],
        ]);

        $this->assertStringContainsString('data-block-id="b1"', $html);
        $this->assertStringContainsString('<strong>Hola</strong>', $html);
        $this->assertStringContainsString('<em>&lt;mundo&gt;</em>', $html);
    }

    public function test_overlapping_marks_nest_without_losing_text(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'abcdef',
                'marks' => [
                    ['type' => 'bold', 'start' => 1, 'end' => 5],
                    ['type' => 'italic', 'start' => 3, 'end' => 6],
                ],
            ]],
        ]);

        $this->assertSame('abcdef', strip_tags($html));
        $this->assertStringContainsString('<strong>bc</strong>', $html);
        $this->assertStringContainsString('<strong><em>de</em></strong>', $html);
        $this->assertStringContainsString('<em>f</em>', $html);
    }

    public function test_list_heading_quote_and_breaks_keep_their_data(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'h1', 'type' => 'heading', 'level' => 2, 'text' => 'Capítulo'],
                ['id' => 'q1', 'type' => 'quote', 'text' => 'Cita'],
                ['id' => 'l1', 'type' => 'list_item', 'text' => 'Uno', 'list_type' => 'ordered', 'depth' => 1],
                ['id' => 's1', 'type' => 'scene_break'],
            ],
        ]);

        $this->assertStringContainsString('<h2 class="editor-block" data-kind="text" data-block-id="h1"', $html);
        $this->assertStringContainsString('>Capítulo</h2>', $html);
        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('data-list-type="ordered" data-depth="1"', $html);
        $this->assertStringContainsString('data-kind="break"', $html);
        $this->assertStringContainsString('&quot;type&quot;:&quot;scene_break&quot;', $html);
    }

    public function test_ordered_list_items_receive_sequential_numbers(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'l1', 'type' => 'list_item', 'text' => 'Uno', 'list_type' => 'ordered'],
                ['id' => 'l2', 'type' => 'list_item', 'text' => 'Dos', 'list_type' => 'ordered'],
                ['id' => 'l3', 'type' => 'list_item', 'text' => 'Viñeta', 'list_type' => 'bullet'],
                ['id' => 'l4', 'type' => 'list_item', 'text' => 'Tres', 'list_type' => 'ordered'],
            ],
        ]);

        $this->assertStringContainsString('data-number="1"', $html);
        $this->assertStringContainsString('data-number="2"', $html);
        $this->assertStringContainsString('data-list-type="bullet"', $html);
        $this->assertStringContainsString('data-number="1"', $html);
    }

    public function test_only_http_links_are_rendered(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'enlace',
                'marks' => [[
                    'type' => 'link',
                    'start' => 0,
                    'end' => 6,
                    'attrs' => ['href' => 'https://example.test/a?b=1'],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('href="https://example.test/a?b=1"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_paragraph_formatting_is_rendered_as_data_and_style(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Texto',
                'marks' => [],
                'align' => 'justify',
                'font_family' => 'serif',
                'font_size' => 22,
                'indent_first' => -1,
                'indent_left' => 2,
                'line_height' => 200,
                'spacing_before' => 12,
                'spacing_after' => 18,
            ]],
        ]);

        foreach ([
            'data-align="justify"',
            'data-font-family="serif"',
            'data-font-size="22"',
            'data-indent-first="-1"',
            'data-indent-left="2"',
            'data-line-height="200"',
            'data-spacing-before="12"',
            'data-spacing-after="18"',
        ] as $attribute) {
            $this->assertStringContainsString($attribute, $html);
        }

        $this->assertStringContainsString('text-align: justify', $html);
        $this->assertStringContainsString('font-size: 22pt', $html);
        $this->assertStringContainsString('text-indent: -1em', $html);
        $this->assertStringContainsString('line-height: 200%', $html);
        $this->assertStringContainsString('margin-top: 12pt', $html);
    }

    public function test_bookmarks_are_rendered_as_data_attributes(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Destino',
                'marks' => [],
                'bookmark' => 'inicio',
            ]],
        ]);

        $this->assertStringContainsString('data-bookmark="inicio"', $html);
    }

    public function test_marks_outside_the_text_are_ignored(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'abc',
                'marks' => [['type' => 'bold', 'start' => 5, 'end' => 9]],
            ]],
        ]);

        $this->assertSame('abc', strip_tags($html));
    }

    public function test_character_styles_render_with_their_definitions(): void
    {
        $html = (new DocumentHtml)
            ->withStyles([5 => ['italic' => true, 'size' => 14, 'color' => '#7a2e12']])
            ->render([
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'paragraph',
                    'text' => 'Hola mundo',
                    'marks' => [[
                        'type' => 'character_style',
                        'start' => 5,
                        'end' => 10,
                        'attrs' => ['id' => '5'],
                    ]],
                ]],
            ]);

        $this->assertStringContainsString('data-mark="character_style"', $html);
        $this->assertStringContainsString('data-style-id="5"', $html);
        $this->assertStringContainsString('font-style: italic', $html);
        $this->assertStringContainsString('font-size: 14pt', $html);
        $this->assertStringContainsString('color: #7a2e12', $html);
        $this->assertStringContainsString('>mundo</span>', $html);
    }

    public function test_note_references_render_an_anchor_with_the_note_number(): void
    {
        $html = (new DocumentHtml)
            ->withNotes([3 => 7])
            ->render([
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'text' => 'Texto con nota',
                    'marks' => [[
                        'type' => 'footnote_reference',
                        'start' => 10,
                        'end' => 14,
                        'attrs' => ['note_id' => 3],
                    ]],
                ]],
            ]);

        $this->assertStringContainsString('data-mark="footnote_reference"', $html);
        $this->assertStringContainsString('data-note-id="3"', $html);
        $this->assertStringContainsString('data-note-number="7"', $html);
        $this->assertStringContainsString('>nota</span>', $html);
        $this->assertSame('Texto con nota', strip_tags($html));
    }

    public function test_tables_render_their_cells_and_caption_as_read_only_atoms(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 't1',
                'type' => 'table',
                'caption' => 'Cronología <real>',
                'rows' => [
                    ['id' => 'r1', 'header' => true, 'cells' => [
                        ['id' => 'c1', 'text' => 'Fecha'],
                        ['id' => 'c2', 'text' => 'Hecho'],
                    ]],
                    ['id' => 'r2', 'cells' => [
                        ['id' => 'c3', 'text' => 'Año <1>'],
                        ['id' => 'c4', 'text' => 'Fundación'],
                    ]],
                ],
            ]],
        ]);

        $this->assertStringContainsString('data-kind="atom"', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<th', $html);
        $this->assertStringContainsString('<figcaption>Cronología &lt;real&gt;</figcaption>', $html);
        $this->assertStringContainsString('Año &lt;1&gt;', $html);
    }

    public function test_images_render_an_authorized_inline_source_with_alt_and_caption(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'i1',
                'type' => 'image',
                'attrs' => [
                    'asset_id' => 7,
                    'alt' => 'Mapa de <Arven>',
                    'caption' => 'Mapa',
                    'width' => 640,
                    'height' => 480,
                    'alignment' => 'center',
                ],
            ]],
        ]);

        $this->assertStringContainsString('src="/workspace/assets/7/view"', $html);
        $this->assertStringContainsString('alt="Mapa de &lt;Arven&gt;"', $html);
        $this->assertStringContainsString('width="640"', $html);
        $this->assertStringContainsString('height="480"', $html);
        $this->assertStringContainsString('data-alignment="center"', $html);
        $this->assertStringContainsString('<figcaption>Mapa</figcaption>', $html);
    }

    public function test_run_font_family_and_size_render_as_resolvable_spans(): void
    {
        $html = (new DocumentHtml)->render([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Hola mundo',
                'marks' => [
                    ['type' => 'font_family', 'start' => 0, 'end' => 4, 'attrs' => ['family' => 'serif']],
                    ['type' => 'font_size', 'start' => 5, 'end' => 10, 'attrs' => ['size' => 18]],
                    ['type' => 'font_family', 'start' => 5, 'end' => 10, 'attrs' => ['family' => 'sans']],
                ],
            ]],
        ]);

        $this->assertStringContainsString('data-mark="font_family"', $html);
        $this->assertStringContainsString('data-value="serif"', $html);
        $this->assertStringContainsString('data-mark="font_size"', $html);
        $this->assertStringContainsString('data-value="18"', $html);
        $this->assertStringContainsString('font-size: 18pt', $html);
        $this->assertStringContainsString('font-family: var(--font-sans)', $html);
        $this->assertSame('Hola mundo', strip_tags($html));
    }
}
