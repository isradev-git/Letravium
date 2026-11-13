import test from 'node:test';
import assert from 'node:assert/strict';

import {removeNoteReferences, setNoteReference} from '../note-format.mjs';

test('setNoteReference adds a note anchor and removes overlapping references', () => {
    const marks = [
        {type: 'bold', start: 0, end: 10},
        {type: 'footnote_reference', start: 2, end: 4, attrs: {note_id: 1}},
    ];

    const result = setNoteReference(marks, 2, 2, 8);

    assert.deepEqual(result, [
        {type: 'bold', start: 0, end: 10},
        {type: 'footnote_reference', start: 2, end: 8, attrs: {note_id: 2}},
    ]);
});

test('removeNoteReferences removes only overlapping note anchors', () => {
    const marks = [
        {type: 'footnote_reference', start: 0, end: 3, attrs: {note_id: 1}},
        {type: 'footnote_reference', start: 5, end: 8, attrs: {note_id: 2}},
        {type: 'italic', start: 0, end: 8},
    ];

    assert.deepEqual(removeNoteReferences(marks, 2, 6), [
        {type: 'italic', start: 0, end: 8},
    ]);
});
