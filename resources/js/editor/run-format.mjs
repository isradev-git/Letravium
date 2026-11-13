const overlapping = (mark, start, end) => mark.start < end && mark.end > start;

export function removeRunMarks(marks, types, start, end) {
    const remove = new Set(types);
    const result = [];

    for (const mark of Array.isArray(marks) ? marks : []) {
        if (!mark || !remove.has(mark.type) || !overlapping(mark, start, end)) {
            result.push(mark);

            continue;
        }

        if (mark.start < start) {
            result.push({...mark, end: start});
        }

        if (mark.end > end) {
            result.push({...mark, start: end});
        }
    }

    return result;
}

export function setRunMark(marks, type, start, end, attrs) {
    return [
        ...removeRunMarks(marks, [type], start, end),
        {type, start, end, attrs},
    ];
}
