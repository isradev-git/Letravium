import test from 'node:test';
import assert from 'node:assert/strict';

import {findMisspellings, findWords, occurrenceAt, wordAt} from '../spellcheck.mjs';

test('findWords keeps Spanish hyphenated and apostrophe words as single tokens', () => {
    const words = findWords('El teórico-práctico y el «l\'amic» son 2026.');

    assert.deepEqual(words.map((entry) => entry.word), ['El', 'teórico-práctico', 'y', 'el', 'l\'amic', 'son']);
    assert.deepEqual(words[1], {word: 'teórico-práctico', start: 3, end: 19});
});

test('findMisspellings reports only rejected words with explicit suggestions', () => {
    const spelling = {
        check: (word) => word !== 'csa',
        suggest: (word) => word === 'csa' ? ['casa', 'cima'] : [],
    };

    const occurrences = findMisspellings('La casa csa.', spelling);

    assert.deepEqual(occurrences, [{
        word: 'csa',
        start: 8,
        end: 11,
        suggestions: ['casa', 'cima'],
    }]);
});

test('findMisspellings respects the limit', () => {
    const spelling = {
        check: () => false,
        suggest: () => ['x'],
    };

    assert.equal(findMisspellings('uno dos tres cuatro', spelling, 2).length, 2);
});

test('occurrenceAt finds the misspelling under a codepoint offset', () => {
    const occurrences = [
        {word: 'csa', start: 3, end: 6, blockId: 'block-1', suggestions: ['casa']},
        {word: 'faro', start: 10, end: 14, blockId: 'block-1', suggestions: []},
    ];

    assert.equal(occurrenceAt(occurrences, 'block-1', 4)?.word, 'csa');
    assert.equal(occurrenceAt(occurrences, 'block-1', 6), null);
    assert.equal(occurrenceAt(occurrences, 'block-2', 4), null);
});

test('wordAt returns the token under a codepoint offset', () => {
    const text = 'La casa de Valdara.';

    assert.deepEqual(wordAt(text, 4), {word: 'casa', start: 3, end: 7});
    assert.equal(wordAt(text, 7), null);
    assert.deepEqual(wordAt(text, 0), {word: 'La', start: 0, end: 2});
});
