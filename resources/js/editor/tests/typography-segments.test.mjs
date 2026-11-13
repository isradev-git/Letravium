import test from 'node:test';
import assert from 'node:assert/strict';

import {transformSegments} from '../typography-segments.mjs';
import {NBSP} from '../typography.mjs';

test('dialogue dash applies when the first word starts another text node', () => {
    const result = transformSegments(['- ', 'Hola.'], {dialogueDash: true});

    assert.deepEqual(result.segments, ['— ', 'Hola.']);
    assert.equal(result.count, 1);
});

test('quotes apply across inline text nodes without losing the inner text', () => {
    const result = transformSegments(['"Hola ', 'mundo"'], {angularQuotes: true});

    assert.deepEqual(result.segments, ['«Hola ', 'mundo»']);
    assert.equal(result.count, 2);
});

test('ellipsis and non-breaking spaces are applied to the selected segments', () => {
    const result = transformSegments(['Son 10 ', '%', ' y esperan...'], {
        nonBreakingSpace: true,
        ellipsis: true,
    });

    assert.deepEqual(result.segments, [`Son 10${NBSP}`, '%', ' y esperan…']);
    assert.equal(result.count, 2);
});

test('disabled rules leave the segments untouched', () => {
    const result = transformSegments(['"Hola" - 10 %...'], {
        angularQuotes: false,
        dialogueDash: false,
        nonBreakingSpace: false,
        ellipsis: false,
    });

    assert.deepEqual(result.segments, ['"Hola" - 10 %...']);
    assert.equal(result.count, 0);
});
