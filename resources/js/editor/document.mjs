const MARK_TAGS = {
    strong: 'bold',
    b: 'bold',
    em: 'italic',
    i: 'italic',
    u: 'underline',
    s: 'strike',
    strike: 'strike',
    del: 'strike',
    sup: 'superscript',
    sub: 'subscript',
};

export function markKey(mark) {
    const type = mark?.type;

    if (type === 'link') {
        return `link:${mark.href}`;
    }

    if (type === 'font_family' || type === 'font_size') {
        return `${type}:${mark.value}`;
    }

    if (type === 'footnote_reference') {
        return `footnote_reference:${mark.noteId}`;
    }

    if (type === 'character_style') {
        return `character_style:${mark.styleId}`;
    }

    return type;
}

const FONT_FAMILY = /^(?:serif|sans|font:[1-9]\d*)$/;

const MANAGED = new Set([
    'bold', 'italic', 'underline', 'strike', 'superscript', 'subscript', 'link',
    'font_family', 'font_size', 'footnote_reference', 'character_style',
]);

export function serializeDocument(root) {
    const blocks = [];

    for (const node of root.childNodes) {
        const result = serializeNode(node);

        if (Array.isArray(result)) {
            blocks.push(...result);
        } else if (result) {
            blocks.push(result);
        }
    }

    return {schema_version: 1, blocks};
}

function serializeNode(node) {
    if (node.nodeType === 3) {
        const text = node.nodeValue ?? '';

        return text.trim() === '' ? null : {id: newBlockId(), type: 'paragraph', text, marks: []};
    }

    if (node.nodeType !== 1) {
        return null;
    }

    const el = node;
    const tag = el.tagName.toLowerCase();

    if (tag === 'br') {
        return null;
    }

    if (el.dataset.kind === 'break' || el.dataset.kind === 'atom') {
        try {
            return JSON.parse(el.dataset.original);
        } catch {
            return null;
        }
    }

    if (tag === 'ul' || tag === 'ol') {
        return serializeList(el, tag === 'ol' ? 'ordered' : 'bullet', 0);
    }

    const nestedList = [...el.children].find((child) => child.tagName === 'UL' || child.tagName === 'OL');

    if (nestedList && onlyNestedList(el, nestedList)) {
        return serializeList(nestedList, nestedList.tagName === 'OL' ? 'ordered' : 'bullet', 0);
    }

    return serializeBlock(el);
}

function onlyNestedList(el, nestedList) {
    for (const child of el.childNodes) {
        if (child === nestedList) {
            continue;
        }

        if (child.nodeType === 3 && child.nodeValue.trim() !== '') {
            return false;
        }

        if (child.nodeType === 1 && child.textContent.trim() !== '') {
            return false;
        }
    }

    return true;
}

function serializeList(list, listType, depth) {
    const blocks = [];

    for (const child of list.children) {
        const tag = child.tagName.toLowerCase();

        if (tag === 'ul' || tag === 'ol') {
            blocks.push(...serializeList(child, tag === 'ol' ? 'ordered' : 'bullet', depth + 1));

            continue;
        }

        if (tag !== 'li') {
            continue;
        }

        const {text, marks} = extractTextAndMarks(child, ['UL', 'OL']);

        if (text !== '') {
            blocks.push({
                id: child.dataset.blockId || newBlockId(),
                type: 'list_item',
                text,
                marks,
                list_type: listType,
                depth,
            });
        }

        for (const nested of child.querySelectorAll(':scope > ul, :scope > ol')) {
            blocks.push(...serializeList(
                nested,
                nested.tagName.toLowerCase() === 'ol' ? 'ordered' : 'bullet',
                depth + 1,
            ));
        }
    }

    return blocks;
}

export function serializeBlock(el) {
    const tag = el.tagName.toLowerCase();
    const {text, marks} = extractTextAndMarks(el);
    let original = {};

    try {
        original = el.dataset.original ? JSON.parse(el.dataset.original) : {};
    } catch {
        original = {};
    }

    const block = {
        ...original,
        id: el.dataset.blockId || newBlockId(),
        marks: [...preservedMarks(original.marks, text), ...marks],
        text,
    };

    if (el.dataset.bookmark) {
        block.bookmark = el.dataset.bookmark;
    } else {
        delete block.bookmark;
    }

    const formatFields = [
        ['align', 'align', false],
        ['fontFamily', 'font_family', false],
        ['fontSize', 'font_size', true],
        ['indentFirst', 'indent_first', true],
        ['indentLeft', 'indent_left', true],
        ['lineHeight', 'line_height', true],
        ['spacingBefore', 'spacing_before', true],
        ['spacingAfter', 'spacing_after', true],
    ];

    for (const [datasetKey, field, numeric] of formatFields) {
        const value = el.dataset[datasetKey];

        if (value === undefined || value === '') {
            delete block[field];

            continue;
        }

        if (numeric) {
            const number = Number(value);

            if (Number.isFinite(number)) {
                block[field] = number;
            } else {
                delete block[field];
            }
        } else {
            block[field] = value;
        }
    }

    if (/^h[1-6]$/.test(tag)) {
        block.type = 'heading';
        block.level = Number(tag[1]);
        delete block.list_type;
        delete block.depth;

        return block;
    }

    if (tag === 'blockquote') {
        block.type = 'quote';
        delete block.level;
        delete block.list_type;
        delete block.depth;

        return block;
    }

    if (el.dataset.listType) {
        block.type = 'list_item';
        block.list_type = el.dataset.listType === 'ordered' ? 'ordered' : 'bullet';
        block.depth = Number(el.dataset.depth || 0);
        delete block.level;

        return block;
    }

    block.type = 'paragraph';
    delete block.level;
    delete block.list_type;
    delete block.depth;

    return block;
}

function preservedMarks(marks, text) {
    if (!Array.isArray(marks)) {
        return [];
    }

    const length = [...text].length;
    const kept = [];

    for (const mark of marks) {
        if (!mark || MANAGED.has(mark.type)) {
            continue;
        }

        if (Number.isInteger(mark.start) || Number.isInteger(mark.end)) {
            const start = Math.max(0, Number(mark.start) || 0);
            const end = Math.min(length, Number(mark.end) || 0);

            if (end <= start) {
                continue;
            }

            kept.push({...mark, start, end});

            continue;
        }

        kept.push(mark);
    }

    return kept;
}

function extractTextAndMarks(root, excludedTags = []) {
    const excluded = new Set(excludedTags.map((tag) => tag.toUpperCase()));
    const walker = root.ownerDocument.createTreeWalker(root, 4, {
        acceptNode(node) {
            let parent = node.parentNode;

            while (parent && parent !== root) {
                if (parent.nodeType === 1 && excluded.has(parent.tagName.toUpperCase())) {
                    return 2;
                }

                parent = parent.parentNode;
            }

            return 1;
        },
    });
    const nodes = [];
    let text = '';
    let offset = 0;

    while (walker.nextNode()) {
        const node = walker.currentNode;
        const value = node.nodeValue ?? '';

        if (value === '') {
            continue;
        }

        const length = [...value].length;
        nodes.push({offset, length, marks: marksForNode(node, root)});
        text += value;
        offset += length;
    }

    const ranges = new Map();

    for (const node of nodes) {
        for (const mark of node.marks) {
            const key = markKey(mark);

            if (!ranges.has(key)) {
                ranges.set(key, {mark, spans: []});
            }

            ranges.get(key).spans.push([node.offset, node.offset + node.length]);
        }
    }

    const marks = [];

    for (const {mark, spans} of ranges.values()) {
        for (const [start, end] of mergeRanges(spans)) {
            if (end <= start) {
                continue;
            }

            marks.push(mark.type === 'link'
                ? {type: 'link', start, end, attrs: {href: mark.href}}
                : mark.type === 'font_family'
                    ? {type: 'font_family', start, end, attrs: {family: mark.value}}
                    : mark.type === 'font_size'
                        ? {type: 'font_size', start, end, attrs: {size: Number(mark.value)}}
                        : mark.type === 'footnote_reference'
                            ? {type: 'footnote_reference', start, end, attrs: {note_id: mark.noteId}}
                            : mark.type === 'character_style'
                                ? {type: 'character_style', start, end, attrs: {id: mark.styleId}}
                                : {type: mark.type, start, end});
        }
    }

    return {text, marks};
}

function marksForNode(node, root) {
    const found = new Map();
    let current = node.parentNode;

    while (current && current !== root) {
        if (current.nodeType === 1) {
            const tag = current.tagName.toLowerCase();
            const type = MARK_TAGS[tag];

            if (type) {
                found.set(type, {type});
            } else if (tag === 'a') {
                const href = current.getAttribute('href') || '';

                if (/^https?:\/\//i.test(href) && !/[\u0000-\u0020\u007f]/.test(href)) {
                    found.set(`link:${href}`, {type: 'link', href});
                }
            } else if (tag === 'span' && current.dataset.mark) {
                const value = current.dataset.value ?? '';

                if (current.dataset.mark === 'font_family' && FONT_FAMILY.test(value)) {
                    found.set(`font_family:${value}`, {type: 'font_family', value});
                }

                if (current.dataset.mark === 'font_size') {
                    const size = Number(value);

                    if (Number.isInteger(size) && size >= 8 && size <= 72) {
                        found.set(`font_size:${size}`, {type: 'font_size', value: size});
                    }
                }

                if (current.dataset.mark === 'footnote_reference') {
                    const noteId = Number(current.dataset.noteId);

                    if (Number.isInteger(noteId) && noteId > 0) {
                        found.set(`footnote_reference:${noteId}`, {type: 'footnote_reference', noteId});
                    }
                }

                if (current.dataset.mark === 'character_style' && current.dataset.styleId) {
                    const styleId = current.dataset.styleId;
                    found.set(`character_style:${styleId}`, {type: 'character_style', styleId});
                }
            }
        }

        current = current.parentNode;
    }

    return [...found.values()];
}

function mergeRanges(spans) {
    const sorted = [...spans].sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    const merged = [];

    for (const span of sorted) {
        const last = merged[merged.length - 1];

        if (last && span[0] <= last[1]) {
            last[1] = Math.max(last[1], span[1]);

            continue;
        }

        merged.push([span[0], span[1]]);
    }

    return merged;
}

export function newBlockId() {
    return `blk-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 9)}`;
}
