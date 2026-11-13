import test from 'node:test';
import assert from 'node:assert/strict';

import {blockHtml, droppedMessage, freshBlockIds, plainTextBlocks} from '../paste.mjs';

test('plain text paste becomes canonical paragraphs without blank lines', () => {
    const blocks = plainTextBlocks('  Primera línea  \r\n\r\nSegunda línea\n   \nTercera');

    assert.deepEqual(blocks.map((block) => block.text), ['Primera línea', 'Segunda línea', 'Tercera']);
    assert.ok(blocks.every((block) => block.type === 'paragraph' && block.marks.length === 0));
    assert.ok(blocks.every((block) => typeof block.id === 'string' && block.id.startsWith('blk-')));
});

test('plain text paste keeps a single line as one paragraph', () => {
    const blocks = plainTextBlocks('Solo una línea');

    assert.equal(blocks.length, 1);
    assert.equal(blocks[0].text, 'Solo una línea');
});

test('block html escapes text and wraps only allowed marks', () => {
    const html = blockHtml({
        text: '<script>alert(1)</script> texto',
        marks: [{type: 'bold', start: 0, end: 8}],
    });

    assert.ok(!html.includes('<script'));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(html.startsWith('<strong>'));
});

test('block html drops unsafe link targets', () => {
    const html = blockHtml({
        text: 'pulsa aquí',
        marks: [{type: 'link', start: 0, end: 11, attrs: {href: 'javascript:alert(1)'}}],
    });

    assert.equal(html, 'pulsa aquí');
});

test('block html wraps overlapping marks without duplicating text', () => {
    const html = blockHtml({
        text: 'abc',
        marks: [
            {type: 'bold', start: 0, end: 2},
            {type: 'italic', start: 1, end: 3},
        ],
    });

    assert.equal(html, '<strong>a</strong><strong><em>b</em></strong><em>c</em>');
});

test('block html renders run font family and size spans', () => {
    const html = blockHtml({
        text: 'Hola mundo',
        marks: [
            {type: 'font_family', start: 0, end: 4, attrs: {family: 'serif'}},
            {type: 'font_size', start: 5, end: 10, attrs: {size: 18}},
        ],
    });

    assert.ok(html.includes('data-mark="font_family" data-value="serif"'));
    assert.ok(html.includes('data-mark="font_size" data-value="18"'));
    assert.ok(html.includes('font-size: 18pt'));
    assert.equal(html.replace(/<[^>]+>/g, ''), 'Hola mundo');
});

test('block html renders character styles from their definitions', () => {
    const html = blockHtml({
        text: 'Hola mundo',
        marks: [{type: 'character_style', start: 5, end: 10, attrs: {id: '5'}}],
    }, {}, {5: {italic: true, size: 14}});

    assert.ok(html.includes('data-mark="character_style"'));
    assert.ok(html.includes('data-style-id="5"'));
    assert.ok(html.includes('font-style: italic'));
    assert.ok(html.includes('font-size: 14pt'));
    assert.equal(html.replace(/<[^>]+>/g, ''), 'Hola mundo');
});

test('block html renders note anchors with the loaded note number', () => {
    const html = blockHtml({
        text: 'Texto con nota',
        marks: [{type: 'footnote_reference', start: 6, end: 10, attrs: {note_id: 3}}],
    }, {3: 7});

    assert.ok(html.includes('data-mark="footnote_reference"'));
    assert.ok(html.includes('data-note-id="3"'));
    assert.ok(html.includes('data-note-number="7"'));
    assert.equal(html.replace(/<[^>]+>/g, ''), 'Texto con nota');
});

test('dropped content produces a Spanish omission notice', () => {
    assert.equal(droppedMessage([]), '');
    assert.equal(droppedMessage(undefined), '');
    assert.equal(
        droppedMessage(['script', 'img']),
        'Se omitió contenido no compatible: script, img.',
    );
});

test('pasted blocks receive fresh unique identities without mutating the server payload', () => {
    const incoming = [
        {id: 'paste-1', type: 'paragraph', text: 'uno', marks: []},
        {id: 'paste-2', type: 'paragraph', text: 'dos', marks: []},
    ];

    const blocks = freshBlockIds(incoming);

    assert.deepEqual(blocks.map((block) => block.text), ['uno', 'dos']);
    assert.notEqual(blocks[0].id, blocks[1].id);
    assert.notEqual(blocks[0].id, 'paste-1');
    assert.equal(incoming[0].id, 'paste-1');
});

test('block html renders a private font family reference', () => {
    const html = blockHtml({
        text: 'Hola',
        marks: [{type: 'font_family', start: 0, end: 4, attrs: {family: 'font:12'}}],
    });

    assert.ok(html.includes('data-value="font:12"'));
    assert.ok(html.includes("font-family: &#039;AppFont12&#039;, serif"));
    assert.equal(html.replace(/<[^>]+>/g, ''), 'Hola');
});

test('block html renders a character style with a private font id', () => {
    const html = blockHtml({
        text: 'Hola',
        marks: [{type: 'character_style', start: 0, end: 4, attrs: {id: '5'}}],
    }, {}, {5: {font_id: 12}});

    assert.ok(html.includes("font-family: &#039;AppFont12&#039;, serif"));
});
