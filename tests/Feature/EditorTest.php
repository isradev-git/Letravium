<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use App\Services\ContentDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsWriter;
use Tests\TestCase;

class EditorTest extends TestCase
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

    private function sceneFor($user): array
    {
        $novel = $this->novelFor($user);

        $chapter = $this->asWriter($user)
            ->postJson("/workspace/novels/{$novel->id}/chapters", ['title' => 'Uno'])
            ->json('chapter');

        return [$novel, $chapter, $chapter['alternatives'][0]];
    }

    private function postScene($user, array $content)
    {
        [, , $alternative] = $this->sceneFor($user);

        return $this->asWriter($user)->postJson(
            "/workspace/alternatives/{$alternative['id']}/scenes",
            ['content' => $content],
        );
    }

    public function test_sanitize_paste_removes_active_content_and_remote_images(): void
    {
        $user = $this->writer();

        $html = '<p>Hola <strong>mundo</strong>'
            .'<script>alert("x")</script>'
            .'<img src="https://evil.test/a.png" onerror="alert(1)">'
            .'<iframe src="https://evil.test"></iframe>'
            .'</p>';

        $response = $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', ['html' => $html])
            ->assertOk()
            ->assertJsonPath('schema_version', 1);

        $json = $response->json();

        $this->assertStringNotContainsString('alert', json_encode($json));
        $this->assertSame('Hola mundo', $json['blocks'][0]['text']);
        $this->assertEqualsCanonicalizing(['script', 'img', 'iframe'], $json['dropped']);
    }

    public function test_sanitize_paste_keeps_allowed_formatting_and_rejects_active_links(): void
    {
        $user = $this->writer();

        $html = '<p>a <strong>b</strong> <em>c</em> <u>d</u> <s>e</s> '
            .'H<sub>2</sub>O x<sup>2</sup> '
            .'<a href="https://example.com/path?q=1">f</a> '
            .'<a href="javascript:alert(1)">g</a></p>';

        $json = $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', ['html' => $html])
            ->assertOk()
            ->json();

        $block = $json['blocks'][0];

        $this->assertSame('a b c d e H2O x2 f g', $block['text']);
        $this->assertContains('a', $json['dropped']);

        $types = array_column($block['marks'], 'type');
        foreach (['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript', 'link'] as $type) {
            $this->assertContains($type, $types);
        }

        $links = array_values(array_filter(
            $block['marks'],
            fn (array $mark) => $mark['type'] === 'link',
        ));

        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/path?q=1', $links[0]['attrs']['href']);
        $this->assertSame(
            'f',
            mb_substr($block['text'], $links[0]['start'], $links[0]['end'] - $links[0]['start']),
        );

        $document = ['schema_version' => 1, 'blocks' => $json['blocks']];
        $this->assertSame($document, app(ContentDocument::class)->validate($document));
    }

    public function test_content_document_rejects_unknown_and_active_marks(): void
    {
        $user = $this->writer();

        $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Hola',
                'marks' => [['type' => 'script', 'start' => 0, 'end' => 4]],
            ]],
        ])->assertStatus(422);

        $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b1',
                'type' => 'paragraph',
                'text' => 'Hola',
                'marks' => [[
                    'type' => 'link',
                    'start' => 0,
                    'end' => 4,
                    'attrs' => ['href' => 'javascript:alert(1)'],
                ]],
            ]],
        ])->assertStatus(422);
    }

    public function test_content_document_rejects_duplicate_block_ids(): void
    {
        $user = $this->writer();

        $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'same', 'type' => 'paragraph', 'text' => 'Uno'],
                ['id' => 'same', 'type' => 'paragraph', 'text' => 'Dos'],
            ],
        ])->assertStatus(422);
    }

    public function test_round_trip_preserves_allowed_marks_and_stable_ids(): void
    {
        $user = $this->writer();

        $content = [
            'schema_version' => 1,
            'blocks' => [
                [
                    'id' => 'stable-1',
                    'type' => 'paragraph',
                    'text' => 'Hola mundo',
                    'marks' => [
                        ['type' => 'bold', 'start' => 0, 'end' => 4],
                        ['type' => 'italic', 'start' => 5, 'end' => 10],
                        ['type' => 'link', 'start' => 0, 'end' => 10, 'attrs' => ['href' => 'https://example.com']],
                        ['type' => 'character_style', 'start' => 0, 'end' => 4, 'attrs' => ['id' => 'enfasis']],
                    ],
                ],
                [
                    'id' => 'stable-2',
                    'type' => 'paragraph',
                    'text' => 'Otra',
                    'marks' => [
                        ['type' => 'underline', 'start' => 0, 'end' => 4],
                        ['type' => 'strike', 'start' => 0, 'end' => 4],
                        ['type' => 'superscript', 'start' => 0, 'end' => 4],
                        ['type' => 'subscript', 'start' => 1, 'end' => 2],
                    ],
                ],
            ],
        ];

        $scene = $this->postScene($user, $content)
            ->assertCreated()
            ->json('scene');

        $this->assertEquals($content, $scene['content']);

        $updated = $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $content])
            ->assertOk()
            ->json('scene');

        $this->assertEquals($content, $updated['content']);
    }

    public function test_paste_endpoint_requires_private_access(): void
    {
        $this->postJson('/workspace/editor/sanitize-paste', ['html' => '<p>Hola</p>'])
            ->assertStatus(401);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['totp_confirmed_at' => now()])->save();

        $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', ['html' => '<p>Hola</p>'])
            ->assertStatus(403);
    }

    public function test_paste_endpoint_validates_size_and_malformed_input(): void
    {
        $user = $this->writer();

        $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', ['html' => str_repeat('a', 262145)])
            ->assertStatus(422);

        $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', [])
            ->assertStatus(422);

        $json = $this->asWriter($user)
            ->postJson('/workspace/editor/sanitize-paste', ['html' => '<p><<<script'])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('blocks', $json);
        $this->assertStringNotContainsString('<script', json_encode($json));
    }

    public function test_structural_blocks_round_trip_with_stable_ids(): void
    {
        $user = $this->writer();

        $content = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'h1', 'type' => 'heading', 'level' => 1, 'text' => 'Capítulo', 'bookmark' => 'inicio'],
                ['id' => 'h3', 'type' => 'heading', 'level' => 3, 'text' => 'Escena menor'],
                ['id' => 'q1', 'type' => 'quote', 'text' => 'Una cita.'],
                ['id' => 'l1', 'type' => 'list_item', 'text' => 'Primero', 'list_type' => 'ordered', 'depth' => 0],
                ['id' => 'l2', 'type' => 'list_item', 'text' => 'Segundo', 'list_type' => 'bullet', 'depth' => 1],
                ['id' => 'sb', 'type' => 'scene_break'],
                ['id' => 'sec', 'type' => 'section_break'],
                ['id' => 'pb', 'type' => 'page_break'],
                [
                    'id' => 'link',
                    'type' => 'paragraph',
                    'text' => 'Enlace',
                    'marks' => [['type' => 'link', 'start' => 0, 'end' => 6, 'attrs' => ['href' => 'https://example.com']]],
                ],
            ],
        ];

        $scene = $this->postScene($user, $content)->assertCreated()->json('scene');
        $this->assertEquals($content, $scene['content']);

        $updated = $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $content])
            ->assertOk()
            ->json('scene');

        $this->assertEquals($content, $updated['content']);
    }

    public function test_invalid_structural_attributes_are_rejected(): void
    {
        $user = $this->writer();

        $cases = [
            [['id' => 'h', 'type' => 'heading', 'level' => 9, 'text' => 'Título']],
            [['id' => 'h', 'type' => 'heading', 'level' => '2', 'text' => 'Título']],
            [['id' => 'l', 'type' => 'list_item', 'text' => 'Elemento', 'list_type' => 'checklist']],
            [['id' => 'l', 'type' => 'list_item', 'text' => 'Elemento', 'depth' => 9]],
            [['id' => 'p', 'type' => 'page_break', 'text' => 'No debe llevar texto']],
            [['id' => 'b', 'type' => 'paragraph', 'text' => 'Uno', 'bookmark' => 123]],
            [
                ['id' => 'b1', 'type' => 'paragraph', 'text' => 'Uno', 'bookmark' => 'dup'],
                ['id' => 'b2', 'type' => 'paragraph', 'text' => 'Dos', 'bookmark' => 'dup'],
            ],
        ];

        foreach ($cases as $index => $blocks) {
            $response = $this->postScene($user, ['schema_version' => 1, 'blocks' => $blocks]);
            $this->assertSame(422, $response->status(), 'case '.$index.': '.$response->getContent());
        }
    }

    public function test_note_references_preserve_identity_and_expose_orphans(): void
    {
        $user = $this->writer();
        [$novel, , $alternative] = $this->sceneFor($user);

        $scene = $this->asWriter($user)->postJson(
            "/workspace/alternatives/{$alternative['id']}/scenes",
            ['content' => ['schema_version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto']]]],
        )->assertCreated()->json('scene');

        $note = $this->asWriter($user)->postJson("/workspace/novels/{$novel->id}/footnotes", [
            'kind' => 'foot',
            'text' => 'Nota con identidad.',
            'scene_id' => $scene['id'],
        ])->assertCreated()->json('footnote');

        $content = ['schema_version' => 1, 'blocks' => [[
            'id' => 'p1',
            'type' => 'paragraph',
            'text' => 'Texto con nota',
            'marks' => [[
                'type' => 'footnote_reference',
                'start' => 6,
                'end' => 10,
                'attrs' => ['note_id' => $note['id']],
            ]],
        ]]];

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $content])
            ->assertOk();

        $audit = $this->asWriter($user)
            ->getJson("/workspace/scenes/{$scene['id']}/notes/audit")
            ->assertOk()
            ->json();

        $this->assertSame([$note['id']], $audit['referenced']);
        $this->assertSame([], $audit['orphaned']);
        $this->assertSame([], $audit['broken']);

        $moved = $content;
        $moved['blocks'][0]['marks'][0]['start'] = 0;
        $moved['blocks'][0]['marks'][0]['end'] = 5;

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $moved])
            ->assertOk();

        $audit = $this->asWriter($user)->getJson("/workspace/scenes/{$scene['id']}/notes/audit")->json();
        $this->assertSame([$note['id']], $audit['referenced']);
        $this->assertSame([], $audit['orphaned']);

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", [
                'content' => ['schema_version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'text' => 'Texto sin nota']]],
            ])->assertOk();

        $audit = $this->asWriter($user)->getJson("/workspace/scenes/{$scene['id']}/notes/audit")->json();
        $this->assertSame([], $audit['referenced']);
        $this->assertSame([$note['id']], array_column($audit['orphaned'], 'id'));
        $this->assertSame('Nota con identidad.', $audit['orphaned'][0]['text']);

        $broken = $content;
        $broken['blocks'][0]['marks'][0]['attrs']['note_id'] = 999999;

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $broken])
            ->assertOk();

        $audit = $this->asWriter($user)->getJson("/workspace/scenes/{$scene['id']}/notes/audit")->json();
        $this->assertSame([999999], $audit['broken']);
    }

    public function test_note_reference_mark_requires_a_positive_note_id(): void
    {
        $user = $this->writer();

        $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'text' => 'Texto',
                'marks' => [['type' => 'footnote_reference', 'start' => 0, 'end' => 4, 'attrs' => []]],
            ]],
        ])->assertStatus(422);
    }

    private function imageAsset(User $owner): MediaAsset
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/figura.png', 'PNG-BYTES');

        return MediaAsset::create([
            'owner_id' => $owner->id,
            'kind' => 'image',
            'original_name' => 'figura.png',
            'mime' => 'image/png',
            'bytes' => 9,
            'path' => 'media/figura.png',
            'metadata' => ['width' => 40, 'height' => 30],
        ]);
    }

    public function test_tables_and_images_round_trip_as_structural_nodes(): void
    {
        $user = $this->writer();
        $asset = $this->imageAsset($user);

        $content = [
            'schema_version' => 1,
            'blocks' => [
                [
                    'id' => 't1',
                    'type' => 'table',
                    'caption' => 'Cronología',
                    'rows' => [
                        ['id' => 'r1', 'header' => true, 'cells' => [
                            ['id' => 'c1', 'text' => 'Fecha'],
                            ['id' => 'c2', 'text' => 'Hecho'],
                        ]],
                        ['id' => 'r2', 'header' => false, 'cells' => [
                            ['id' => 'c3', 'text' => 'Año 1'],
                            ['id' => 'c4', 'text' => 'Fundación'],
                        ]],
                    ],
                ],
                [
                    'id' => 'img1',
                    'type' => 'image',
                    'attrs' => [
                        'asset_id' => $asset->id,
                        'alt' => 'Mapa de Arven',
                        'caption' => 'Mapa',
                        'width' => 640,
                        'height' => 480,
                        'alignment' => 'center',
                    ],
                ],
                ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Después de la tabla.'],
            ],
        ];

        $scene = $this->postScene($user, $content)->assertCreated()->json('scene');
        $this->assertEquals($content, $scene['content']);

        $updated = $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}", ['content' => $content])
            ->assertOk()
            ->json('scene');

        $this->assertEquals($content, $updated['content']);
    }

    public function test_invalid_table_and_image_structures_are_rejected(): void
    {
        $user = $this->writer();
        $asset = $this->imageAsset($user);

        $cases = [
            [['id' => 't', 'type' => 'table', 'rows' => []]],
            [['id' => 't', 'type' => 'table', 'rows' => [['id' => 'r1', 'cells' => []]]]],
            [['id' => 't', 'type' => 'table', 'rows' => [
                ['id' => 'r1', 'cells' => [['id' => 'c1', 'text' => 'A'], ['id' => 'c1', 'text' => 'B']]],
            ]]],
            [['id' => 'i', 'type' => 'image', 'attrs' => ['asset_id' => 0]]],
            [['id' => 'i', 'type' => 'image', 'attrs' => ['asset_id' => 'x']]],
            [['id' => 'i', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'alignment' => 'diagonal']]],
            [['id' => 'i', 'type' => 'image', 'attrs' => ['asset_id' => $asset->id, 'width' => -3]]],
        ];

        foreach ($cases as $index => $blocks) {
            $response = $this->postScene($user, ['schema_version' => 1, 'blocks' => $blocks]);
            $this->assertSame(422, $response->status(), 'case '.$index.': '.$response->getContent());
        }
    }

    public function test_scene_content_cannot_reference_another_accounts_image(): void
    {
        $user = $this->writer();
        $other = $this->writer();
        $foreign = $this->imageAsset($other);

        $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'i',
                'type' => 'image',
                'attrs' => ['asset_id' => $foreign->id, 'alt' => 'Ajena'],
            ]],
        ])->assertStatus(422);
    }

    public function test_scene_save_preserves_text_spacing_used_by_mark_offsets(): void
    {
        $user = $this->writer();
        $scene = $this->postScene($user, [
            'schema_version' => 1,
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'base', 'marks' => []]],
        ])->assertCreated()->json('scene');

        $this->asWriter($user)
            ->putJson("/workspace/scenes/{$scene['id']}/content", [
                'base_revision' => 1,
                'content' => [
                    'schema_version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => 'paragraph',
                        'text' => ' hola mundo ',
                        'marks' => [['type' => 'bold', 'start' => 1, 'end' => 5]],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('scene.content.blocks.0.text', ' hola mundo ');
    }
}
