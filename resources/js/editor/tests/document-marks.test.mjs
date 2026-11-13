import test from 'node:test';
import assert from 'node:assert/strict';

import {markKey} from '../document.mjs';

test('markKey distinguishes styled runs, notes and links with different attributes', () => {
    assert.notEqual(
        markKey({type: 'character_style', styleId: '2'}),
        markKey({type: 'character_style', styleId: '3'}),
    );
    assert.notEqual(
        markKey({type: 'footnote_reference', noteId: 1}),
        markKey({type: 'footnote_reference', noteId: 2}),
    );
    assert.notEqual(
        markKey({type: 'font_size', value: 14}),
        markKey({type: 'font_size', value: 18}),
    );
    assert.notEqual(
        markKey({type: 'link', href: 'https://a.test'}),
        markKey({type: 'link', href: 'https://b.test'}),
    );
    assert.equal(markKey({type: 'bold'}), 'bold');
});
