import {newBlockId} from './document.mjs';

const ALIGNMENTS = ['left', 'center', 'right'];

export function atomHtml(block) {
    if (block?.type === 'table') {
        return tableHtml(block);
    }

    if (block?.type === 'image') {
        return imageHtml(block);
    }

    return '';
}

function tableHtml(block) {
    let rows = '';

    for (const row of Array.isArray(block.rows) ? block.rows : []) {
        if (!row || !Array.isArray(row.cells)) {
            continue;
        }

        const header = row.header === true;
        const tag = header ? 'th' : 'td';
        const scope = header ? ' scope="col"' : '';
        rows += '<tr>';

        for (const cell of row.cells) {
            rows += `<${tag}${scope}>${escapeHtml(cell?.text ?? '')}</${tag}>`;
        }

        rows += '</tr>';
    }

    const caption = typeof block.caption === 'string' && block.caption.trim() !== ''
        ? `<figcaption>${escapeHtml(block.caption)}</figcaption>`
        : '';

    return `<figure class="editor-figure editor-table"><table><tbody>${rows}</tbody></table>${caption}</figure>`;
}

function imageHtml(block) {
    const attrs = block.attrs ?? {};
    const assetId = Number.isInteger(attrs.asset_id) && attrs.asset_id > 0 ? attrs.asset_id : null;

    if (assetId === null) {
        return '<span class="muted">Imagen no válida.</span>';
    }

    const alignment = ALIGNMENTS.includes(attrs.alignment) ? attrs.alignment : 'center';
    let img = `<img src="/workspace/assets/${assetId}/view" alt="${escapeHtml(attrs.alt ?? '')}"`;

    for (const key of ['width', 'height']) {
        if (Number.isInteger(attrs[key]) && attrs[key] > 0) {
            img += ` ${key}="${attrs[key]}"`;
        }
    }

    img += ' loading="lazy">';
    const caption = typeof attrs.caption === 'string' && attrs.caption.trim() !== ''
        ? `<figcaption>${escapeHtml(attrs.caption)}</figcaption>`
        : '';

    return `<figure class="editor-figure editor-image" data-alignment="${alignment}">${img}${caption}</figure>`;
}

export function buildTableBlock({id, caption = '', rows} = {}) {
    const normalized = [];

    for (const row of Array.isArray(rows) ? rows : []) {
        if (!row || !Array.isArray(row.cells)) {
            continue;
        }

        const cells = row.cells
            .filter((cell) => cell && String(cell.text ?? '').trim() !== '')
            .map((cell) => ({
                id: typeof cell.id === 'string' && cell.id !== '' ? cell.id : newBlockId(),
                text: String(cell.text ?? ''),
            }));

        if (cells.length === 0) {
            continue;
        }

        normalized.push({
            id: typeof row.id === 'string' && row.id !== '' ? row.id : newBlockId(),
            header: row.header === true,
            cells,
        });
    }

    if (normalized.length === 0) {
        return null;
    }

    const block = {
        id: typeof id === 'string' && id !== '' ? id : newBlockId(),
        type: 'table',
    };

    if (typeof caption === 'string' && caption.trim() !== '') {
        block.caption = caption.trim();
    }

    block.rows = normalized;

    return block;
}

export function buildImageBlock({id, assetId, alt = '', caption = '', width = null, height = null, alignment = 'center'} = {}) {
    if (!Number.isInteger(assetId) || assetId < 1) {
        return null;
    }

    const attrs = {
        asset_id: assetId,
        alt: String(alt).trim(),
        alignment: ALIGNMENTS.includes(alignment) ? alignment : 'center',
    };

    if (String(caption).trim() !== '') {
        attrs.caption = String(caption).trim();
    }

    if (Number.isInteger(width) && width > 0) {
        attrs.width = width;
    }

    if (Number.isInteger(height) && height > 0) {
        attrs.height = height;
    }

    return {
        id: typeof id === 'string' && id !== '' ? id : newBlockId(),
        type: 'image',
        attrs,
    };
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
