import {apply} from './typography.mjs';

const RULES = [
    {name: 'ellipsis', option: 'ellipsis'},
    {name: 'angularQuotes', option: 'angularQuotes'},
    {name: 'dialogueDash', option: 'dialogueDash'},
    {name: 'nonBreakingSpace', option: 'nonBreakingSpace'},
];

const settingsFor = (option) => ({
    dialogueDash: false,
    angularQuotes: false,
    ellipsis: false,
    nonBreakingSpace: false,
    [option]: true,
});

export function transformSegments(values, options = {}) {
    const segments = (Array.isArray(values) ? values : []).map((value) => String(value ?? ''));
    let count = 0;

    for (const rule of RULES) {
        if (options[rule.option] === false) {
            continue;
        }

        const text = segments.join('');
        const result = apply(text, settingsFor(rule.option));
        const edits = [...result.edits].sort((a, b) => b.sourceStart - a.sourceStart);

        for (const edit of edits) {
            const changes = granularChanges(edit.before, edit.after)
                .sort((a, b) => b.offset - a.offset);

            for (const change of changes) {
                const start = edit.sourceStart + change.offset;
                replaceRange(segments, start, start + change.removeLength, change.insert);
                count++;
            }
        }
    }

    return {segments, count};
}

function granularChanges(before, after) {
    if (before.length === after.length) {
        const changes = [];

        for (let index = 0; index < before.length; index++) {
            if (before[index] !== after[index]) {
                changes.push({offset: index, removeLength: 1, insert: after[index]});
            }
        }

        return changes;
    }

    let prefix = 0;

    while (prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) {
        prefix++;
    }

    let suffix = 0;

    while (
        suffix < before.length - prefix
        && suffix < after.length - prefix
        && before[before.length - 1 - suffix] === after[after.length - 1 - suffix]
    ) {
        suffix++;
    }

    return [{
        offset: prefix,
        removeLength: before.length - prefix - suffix,
        insert: after.slice(prefix, after.length - suffix),
    }];
}

function replaceRange(segments, start, end, insert) {
    if (segments.length === 0) {
        return;
    }

    let startIndex = 0;
    let startOffset = start;

    while (startIndex < segments.length && startOffset > segments[startIndex].length) {
        startOffset -= segments[startIndex].length;
        startIndex++;
    }

    if (startIndex >= segments.length) {
        startIndex = segments.length - 1;
        startOffset = segments[startIndex].length;
    }

    let endIndex = startIndex;
    let endOffset = end - start + startOffset;

    while (endIndex < segments.length && endOffset > segments[endIndex].length) {
        endOffset -= segments[endIndex].length;
        endIndex++;
    }

    if (endIndex >= segments.length) {
        endIndex = segments.length - 1;
        endOffset = segments[endIndex].length;
    }

    if (startIndex === endIndex) {
        const value = segments[startIndex];
        segments[startIndex] = value.slice(0, startOffset) + insert + value.slice(endOffset);

        return;
    }

    segments[startIndex] = segments[startIndex].slice(0, startOffset) + insert;

    for (let index = startIndex + 1; index < endIndex; index++) {
        segments[index] = '';
    }

    segments[endIndex] = segments[endIndex].slice(endOffset);
}
