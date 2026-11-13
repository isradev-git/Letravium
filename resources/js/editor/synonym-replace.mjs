export function replaceRange(data, start, end, replacement) {
    if (!data || typeof data.text !== 'string') {
        return null;
    }

    const characters = [...data.text];

    if (!Number.isInteger(start) || !Number.isInteger(end)
        || start < 0 || end <= start || end > characters.length) {
        return null;
    }

    const value = String(replacement ?? '');

    if (value === '') {
        return null;
    }

    const added = [...value].length - (end - start);
    const text = characters.slice(0, start).join('') + value + characters.slice(end).join('');
    const marks = (Array.isArray(data.marks) ? data.marks : []).map((mark) => {
        if (!Number.isInteger(mark?.start) || !Number.isInteger(mark?.end)) {
            return mark;
        }

        if (mark.end <= start) {
            return mark;
        }

        if (mark.start >= end) {
            return {...mark, start: mark.start + added, end: mark.end + added};
        }

        return {
            ...mark,
            start: Math.min(mark.start, start),
            end: Math.max(start + [...value].length, mark.end + added),
        };
    });

    return {...data, text, marks};
}
