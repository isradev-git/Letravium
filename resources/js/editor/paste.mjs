import {newBlockId} from './document.mjs';
import {styleCss} from './character-style.mjs';

const MARK_ORDER = {
    link: 0,
    bold: 1,
    italic: 2,
    underline: 3,
    strike: 4,
    superscript: 5,
    subscript: 6,
    font_family: 7,
    font_size: 8,
    footnote_reference: 9,
    character_style: 10,
};

const FONT_FAMILY = /^(?:serif|sans|font:[1-9]\d*)$/;

const MARK_TAGS = {
    bold: 'strong',
    italic: 'em',
    underline: 'u',
    strike: 's',
    superscript: 'sup',
    subscript: 'sub',
};

export function plainTextBlocks(text) {
    return String(text ?? '')
        .replace(/\r\n?/g, '\n')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => ({
            id: newBlockId(),
            type: 'paragraph',
            text: line,
            marks: [],
        }));
}

export function freshBlockIds(blocks) {
    return (Array.isArray(blocks) ? blocks : []).map((block) => ({
        ...block,
        id: newBlockId(),
    }));
}

export function droppedMessage(dropped) {
    const list = Array.isArray(dropped)
        ? dropped.filter((tag) => typeof tag === 'string' && tag !== '')
        : [];

    return list.length ? `Se omitió contenido no compatible: ${list.join(', ')}.` : '';
}

export function blockHtml(block, notes = {}, styles = {}) {
    const characters = Array.from(typeof block?.text === 'string' ? block.text : '');
    const length = characters.length;
    const active = [];

    for (const mark of Array.isArray(block?.marks) ? block.marks : []) {
        const type = mark?.type;

        if (!(type in MARK_ORDER)) {
            continue;
        }

        const start = mark.start;
        const end = mark.end;

        if (!Number.isInteger(start) || !Number.isInteger(end)) {
            continue;
        }

        const safeStart = Math.max(0, start);
        const safeEnd = Math.min(length, end);

        if (safeEnd <= safeStart) {
            continue;
        }

        const href = type === 'link' ? mark?.attrs?.href : null;

        if (type === 'link' && !safeHref(href)) {
            continue;
        }

        if (type === 'font_family' && !FONT_FAMILY.test(mark?.attrs?.family ?? '')) {
            continue;
        }

        if (type === 'font_size') {
            const size = mark?.attrs?.size;

            if (!Number.isInteger(size) || size < 8 || size > 72) {
                continue;
            }
        }

        if (type === 'footnote_reference') {
            const noteId = mark?.attrs?.note_id;

            if (!Number.isInteger(noteId) || noteId < 1) {
                continue;
            }
        }

        if (type === 'character_style') {
            const styleId = mark?.attrs?.id ?? mark?.attrs?.style_id;

            if (typeof styleId !== 'string' || styleId === '') {
                continue;
            }
        }

        active.push({type, start: safeStart, end: safeEnd, href, attrs: mark?.attrs ?? {}});
    }

    if (active.length === 0 || length === 0) {
        return escapeHtml(characters.join(''));
    }

    const points = new Set([0, length]);

    for (const mark of active) {
        points.add(mark.start);
        points.add(mark.end);
    }

    const sorted = [...points].sort((a, b) => a - b);
    let html = '';
    let buffer = '';
    let previousKey = null;
    let previousMarks = [];

    for (let index = 0; index < sorted.length - 1; index++) {
        const start = sorted[index];
        const end = sorted[index + 1];
        const marks = active
            .filter((mark) => mark.start <= start && mark.end >= end)
            .sort((a, b) => MARK_ORDER[a.type] - MARK_ORDER[b.type]);
        const key = JSON.stringify(marks.map((mark) => [mark.type, mark.href, mark.attrs]));
        const segment = characters.slice(start, end).join('');

        if (key === previousKey) {
            buffer += segment;

            continue;
        }

        html += wrap(buffer, previousMarks, notes, styles);
        buffer = segment;
        previousKey = key;
        previousMarks = marks;
    }

    return html + wrap(buffer, previousMarks, notes, styles);
}

function wrap(text, marks, notes, styles) {
    let html = escapeHtml(text);

    for (const mark of [...marks].reverse()) {
        if (mark.type === 'link') {
            html = `<a href="${escapeHtml(mark.href)}" rel="noopener noreferrer">${html}</a>`;

            continue;
        }

        if (mark.type === 'font_family') {
            const family = mark.attrs.family;
            const css = fontFamilyCss(family);

            html = `<span data-mark="font_family" data-value="${escapeHtml(family)}" style="font-family: ${escapeHtml(css)}">${html}</span>`;

            continue;
        }

        if (mark.type === 'font_size') {
            const size = mark.attrs.size;

            html = `<span data-mark="font_size" data-value="${size}" style="font-size: ${size}pt">${html}</span>`;

            continue;
        }

        if (mark.type === 'footnote_reference') {
            const noteId = mark.attrs.note_id;
            const number = Number.isInteger(notes[noteId]) ? String(notes[noteId]) : '?';

            html = `<span class="editor-note-anchor" data-mark="footnote_reference" data-note-id="${noteId}" data-note-number="${number}" title="Nota ${number}">${html}</span>`;

            continue;
        }

        if (mark.type === 'character_style') {
            const styleId = String(mark.attrs.id);
            const css = styleCss(styles[styleId] ?? {});
            const style = css !== '' ? ` style="${escapeHtml(css)}"` : '';

            html = `<span class="editor-character-style" data-mark="character_style" data-style-id="${escapeHtml(styleId)}"${style}>${html}</span>`;

            continue;
        }

        const tag = MARK_TAGS[mark.type];

        html = `<${tag}>${html}</${tag}>`;
    }

    return html;
}

function fontFamilyCss(family) {
    if (family === 'sans') {
        return 'var(--font-sans)';
    }

    if (/^font:\d+$/.test(family)) {
        return `'AppFont${family.slice(5)}', serif`;
    }

    return "'Source Serif 4', Georgia, serif";
}

function safeHref(href) {
    return typeof href === 'string'
        && /^https?:\/\//i.test(href)
        && !/[\u0000-\u0020\u007f]/.test(href);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
