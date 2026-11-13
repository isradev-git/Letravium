import test from 'node:test';
import assert from 'node:assert/strict';

import {removeRunMarks, setRunMark} from '../run-format.mjs';

test('removeRunMarks removes a fully covered run and keeps other marks', () => {
    const marks = [
        {type: 'bold', start: 0, end: 10},
        {type: 'font_size', start: 2, end: 5, attrs: {size: 18}},
    ];

    const result = removeRunMarks(marks, ['font_size'], 1, 6);

    assert.deepEqual(result, [{type: 'bold', start: 0, end: 10}]);
});

test('removeRunMarks splits a partially covered run into the untouched sides', () => {
    const marks = [{type: 'font_family', start: 2, end: 8, attrs: {family: 'serif'}}];

    const result = removeRunMarks(marks, ['font_family'], 4, 6);

    assert.deepEqual(result, [
        {type: 'font_family', start: 2, end: 4, attrs: {family: 'serif'}},
        {type: 'font_family', start: 6, end: 8, attrs: {family: 'serif'}},
    ]);
});

test('removeRunMarks drops an empty side after trimming at the edge', () => {
    const marks = [{type: 'font_size', start: 2, end: 8, attrs: {size: 20}}];

    assert.deepEqual(removeRunMarks(marks, ['font_size'], 2, 5), [
        {type: 'font_size', start: 5, end: 8, attrs: {size: 20}},
    ]);
    assert.deepEqual(removeRunMarks(marks, ['font_size'], 5, 8), [
        {type: 'font_size', start: 2, end: 5, attrs: {size: 20}},
    ]);
});

test('removeRunMarks leaves previously removed ranges untouched', () => {
    const marks = [{type: 'font_size', start: 0, end: 2, attrs: {size: 12}}];

    assert.deepEqual(removeRunMarks(marks, ['font_size'], 4, 6), marks);
});

test('setRunMark replaces only the selected range and appends the new value', () => {
    const marks = [
        {type: 'bold', start: 0, end: 10},
        {type: 'font_family', start: 2, end: 8, attrs: {family: 'serif'}},
    ];

    const result = setRunMark(marks, 'font_family', 4, 6, {family: 'sans'});

    assert.deepEqual(result, [
        {type: 'bold', start: 0, end: 10},
        {type: 'font_family', start: 2, end: 4, attrs: {family: 'serif'}},
        {type: 'font_family', start: 6, end: 8, attrs: {family: 'serif'}},
        {type: 'font_family', start: 4, end: 6, attrs: {family: 'sans'}},
    ]);
});
