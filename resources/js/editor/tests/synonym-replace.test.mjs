import test from 'node:test';
import assert from 'node:assert/strict';

import {replaceRange} from '../synonym-replace.mjs';

test('replacing a word shifts later marks and expands a mark that contains it', () => {
    const data = {
        id: 'b1',
        type: 'paragraph',
        text: 'La casa azul',
        marks: [
            {type: 'bold', start: 0, end: 2},
            {type: 'italic', start: 3, end: 7},
            {type: 'underline', start: 8, end: 12},
        ],
    };

    const result = replaceRange(data, 3, 7, 'vivienda');

    assert.equal(result.text, 'La vivienda azul');
    assert.deepEqual(result.marks, [
        {type: 'bold', start: 0, end: 2},
        {type: 'italic', start: 3, end: 11},
        {type: 'underline', start: 12, end: 16},
    ]);
});

test('replacing range preserves links and non-overlapping marks', () => {
    const data = {
        id: 'b1',
        type: 'paragraph',
        text: 'uno dos tres',
        marks: [
            {type: 'link', start: 0, end: 3, attrs: {href: 'https://example.test'}},
            {type: 'strike', start: 8, end: 12},
        ],
    };

    const result = replaceRange(data, 4, 7, 'doscientos');

    assert.equal(result.text, 'uno doscientos tres');
    assert.deepEqual(result.marks, [
        {type: 'link', start: 0, end: 3, attrs: {href: 'https://example.test'}},
        {type: 'strike', start: 15, end: 19},
    ]);
});

test('invalid or empty ranges are rejected', () => {
    const data = {id: 'b1', type: 'paragraph', text: 'casa', marks: []};

    assert.equal(replaceRange(data, 2, 2, 'x'), null);
    assert.equal(replaceRange(data, 4, 2, 'x'), null);
    assert.equal(replaceRange(data, 0, 9, 'x'), null);
    assert.equal(replaceRange(null, 0, 1, 'x'), null);
});
