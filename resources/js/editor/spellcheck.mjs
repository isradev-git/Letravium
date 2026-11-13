const WORD = /\p{L}+(?:['’\-]\p{L}+)*/gu;

export function findWords(text) {
    const source = String(text ?? '');
    const words = [];

    for (const match of source.matchAll(WORD)) {
        const word = match[0];
        const start = [...source.slice(0, match.index)].length;
        words.push({word, start, end: start + [...word].length});
    }

    return words;
}

export function findMisspellings(text, spelling, limit = 40) {
    const occurrences = [];

    for (const token of findWords(text)) {
        if (occurrences.length >= limit) {
            break;
        }

        if (spelling.check(token.word)) {
            continue;
        }

        occurrences.push({
            ...token,
            suggestions: spelling.suggest(token.word).slice(0, 5),
        });
    }

    return occurrences;
}

export function occurrenceAt(occurrences, blockId, offset) {
    return occurrences.find(
        (occurrence) => occurrence.blockId === blockId && offset >= occurrence.start && offset < occurrence.end,
    ) ?? null;
}

export function wordAt(text, offset) {
    return findWords(text).find(
        (token) => offset >= token.start && offset < token.end,
    ) ?? null;
}
