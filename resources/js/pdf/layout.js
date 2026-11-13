import { PDFDocument, rgb } from 'pdf-lib';
import fontkit from 'pdf-fontkit';

const MM = 72 / 25.4;
const BODY_SIZE = 10;
const BODY_LINE = 14;
const HEADING_SIZE = 20;
const HEADING_LINE = 26;
const NOTE_SIZE = 7;
const NOTE_LINE = 9;
const TABLE_SIZE = 8.5;
const TABLE_ROW = 14;
const INK = rgb(0.14, 0.21, 0.17);
const MUTED = rgb(0.29, 0.42, 0.34);

function wrap(text, font, size, maxWidth) {
  const words = String(text).split(/\s+/).filter(Boolean);
  const lines = [];
  let line = '';

  for (const word of words) {
    const candidate = line === '' ? word : `${line} ${word}`;

    if (font.widthOfTextAtSize(candidate, size) <= maxWidth) {
      line = candidate;
    } else {
      if (line !== '') {
        lines.push(line);
      }

      line = word;
    }
  }

  if (line !== '') {
    lines.push(line);
  }

  return lines.length > 0 ? lines : [''];
}

function noteLines(note, contentWidth) {
  const prefix = `${note.number}. `;
  const lines = wrap(note.body, note.font, NOTE_SIZE, contentWidth - 4);

  return lines.map((text, index) => ({
    text: (index === 0 ? prefix : '') + text,
    noteId: note.id,
    first: index === 0,
  }));
}

function millimetres(value, fallback) {
  const match = String(value ?? '').match(/(\d+(?:\.\d+)?)/);

  return match ? Number(match[1]) : fallback;
}

function pageSizeMm(label, orientation) {
  const match = String(label ?? '').match(/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/i);
  let width = match ? Number(match[1]) : 148;
  let height = match ? Number(match[2]) : 210;

  if (orientation === 'landscape' && width < height) {
    [width, height] = [height, width];
  }

  return [width, height];
}

function resolveRunningHead(value, pageNumber, title, chapterTitle) {
  let token = null;

  if (typeof value === 'string') {
    token = value;
  } else if (Array.isArray(value)) {
    token = value.find((item) => typeof item === 'string' && item.trim() !== '') ?? null;
  } else if (value && typeof value === 'object') {
    const odd = typeof value.odd === 'string' ? value.odd : null;
    const even = typeof value.even === 'string' ? value.even : null;
    token = pageNumber % 2 === 1 ? (odd || even) : (even || odd);
  }

  if (typeof token !== 'string' || token.trim() === '') {
    return null;
  }

  const resolved = token.trim().toLowerCase();

  if (resolved === 'chapter') {
    return chapterTitle || title;
  }

  if (resolved === 'book') {
    return title;
  }

  return token.trim();
}

export async function generatePdf({ fixture, fonts, media }) {
  const doc = await PDFDocument.create();
  doc.registerFontkit(fontkit);
  const metadata = fixture.metadata ?? {};
  const layout = fixture.layout ?? {};
  const [pageWidthMm, pageHeightMm] = pageSizeMm(layout.page_size, layout.orientation);
  const PAGE_W = pageWidthMm * MM;
  const PAGE_H = pageHeightMm * MM;
  const margins = layout.margins ?? {};
  const MARGIN = {
    top: millimetres(margins.top, 18) * MM,
    bottom: millimetres(margins.bottom, 22) * MM,
    left: millimetres(margins.left, 16) * MM,
    right: millimetres(margins.right, 16) * MM,
  };
  const CONTENT_W = PAGE_W - MARGIN.left - MARGIN.right;
  const CONTENT_BOTTOM = MARGIN.bottom;
  const NOTE_BAND = 150;
  const BODY_BOTTOM = CONTENT_BOTTOM + NOTE_BAND + 8;
  const runningHeads = layout.running_head ?? layout.running_heads;
  const title = String(metadata.title ?? 'Libro');
  doc.setTitle(title);

  if (metadata.author) {
    doc.setAuthor(String(metadata.author));
  }

  if (metadata.subtitle) {
    doc.setSubject(String(metadata.subtitle));
  }

  doc.setLanguage(String(metadata.language ?? 'es'));

  const bodyFont = await doc.embedFont(fonts.body, { subset: true });
  const headingFont = await doc.embedFont(fonts.heading, { subset: true });
  const images = new Map();

  async function imageFor(assetId) {
    const key = Number(assetId);

    if (!images.has(key)) {
      const bytes = media?.[key];

      if (!bytes) {
        throw new Error(`Imagen no disponible: ${assetId}`);
      }

      const data = new Uint8Array(bytes);
      const isJpeg = data.length > 2 && data[0] === 0xff && data[1] === 0xd8;
      const isWebp = data.length > 12
        && String.fromCharCode(...data.slice(0, 4)) === 'RIFF'
        && String.fromCharCode(...data.slice(8, 12)) === 'WEBP';

      if (isWebp) {
        throw new Error(`Formato WebP no admitido en PDF: ${assetId}`);
      }

      images.set(key, isJpeg ? await doc.embedJpg(data) : await doc.embedPng(data));
    }

    return images.get(key);
  }

  let page = null;
  let pageIndex = 0;
  let y = 0;
  let noteY = 0;
  let pendingNoteLines = [];
  let currentNoteLines = [];
  const chapterPages = {};
  const scenePages = {};
  const noteRefPages = {};
  let currentChapterTitle = null;

  function drawHeader() {
    const text = resolveRunningHead(runningHeads, pageIndex, title, currentChapterTitle);

    if (!text) {
      return;
    }

    page.drawText(text, {
      x: MARGIN.left,
      y: PAGE_H - 10 * MM,
      size: 7,
      font: headingFont,
      color: MUTED,
    });
  }

  function drawFooter() {
    const label = String(pageIndex);
    const width = headingFont.widthOfTextAtSize(label, 9);
    page.drawText(label, {
      x: PAGE_W / 2 - width / 2,
      y: MARGIN.bottom / 2,
      size: 9,
      font: headingFont,
      color: MUTED,
    });
  }

  function drawNoteBlock(lines) {
    let index = 0;

    while (index < lines.length) {
      const line = lines[index];

      if (noteY - NOTE_LINE < CONTENT_BOTTOM) {
        pendingNoteLines = lines.slice(index);
        return;
      }

      page.drawText(line.text, {
        x: MARGIN.left + 2,
        y: noteY - NOTE_LINE + 2,
        size: NOTE_SIZE,
        font: bodyFont,
        color: INK,
      });
      noteY -= NOTE_LINE;
      index++;
    }
  }

  function finishPage() {
    if (page === null) {
      return;
    }

    if (currentNoteLines.length > 0) {
      drawNoteBlock(currentNoteLines);
      currentNoteLines = [];
    }
  }

  function addPage() {
    finishPage();

    page = doc.addPage([PAGE_W, PAGE_H]);
    pageIndex++;
    y = PAGE_H - MARGIN.top;
    noteY = CONTENT_BOTTOM + NOTE_BAND;

    if (pageIndex > 1) {
      drawHeader();
    }

    drawFooter();

    if (pendingNoteLines.length > 0) {
      const carried = pendingNoteLines;
      pendingNoteLines = [];
      drawNoteBlock(carried);
    }
  }

  function ensure(space) {
    if (y - space < BODY_BOTTOM) {
      addPage();
    }
  }

  function drawTextBlock(text, options = {}) {
    const indent = options.indent ?? 0;
    const font = options.font ?? bodyFont;
    const size = options.size ?? BODY_SIZE;
    const lineHeight = options.lineHeight ?? BODY_LINE;
    const color = options.color ?? INK;
    const lines = wrap(text, font, size, CONTENT_W - indent);
    let lastBaseline = y;
    let lastWidth = 0;

    for (const line of lines) {
      ensure(lineHeight);
      lastBaseline = y - size;
      lastWidth = font.widthOfTextAtSize(line, size);
      page.drawText(line, {
        x: MARGIN.left + indent,
        y: lastBaseline,
        size,
        font,
        color,
      });
      y -= lineHeight;
    }

    y -= options.gap ?? 4;

    return { lastBaseline, lastWidth };
  }

  function drawParagraph(text) {
    const markers = [...text.matchAll(/\[\[([A-Z0-9]+)\]\]/g)].map((match) => match[1]);
    const clean = text.replace(/\[\[[A-Z0-9]+\]\]/g, '').trim();
    const { lastBaseline, lastWidth } = drawTextBlock(clean);

    if (markers.length > 0) {
      let markerX = MARGIN.left + Math.min(lastWidth + 3, CONTENT_W - 10);

      for (const id of markers) {
        const note = fixtureNotes[id];

        if (!note) {
          continue;
        }

        page.drawText(String(note.number), {
          x: markerX,
          y: lastBaseline + 4,
          size: 6,
          font: bodyFont,
          color: INK,
        });
        markerX += 5;

        noteRefPages[id] = pageIndex;
        currentNoteLines.push(...noteLines({ ...note, id, font: bodyFont }, CONTENT_W));
      }
    }
  }

  function drawTable(block) {
    const pad = 4;
    const cellSize = 7.5;
    const cellLine = 9;
    const headerCells = Array.isArray(block.headers) ? block.headers : [];
    const bodyCells = (block.rows ?? []).filter((row) => Array.isArray(row));
    const columns = Math.max(headerCells.length, ...bodyCells.map((row) => row.length), 0);

    if (columns === 0) {
      return;
    }

    const widths = Array.from({ length: columns }, () => CONTENT_W / columns);
    const total = widths.reduce((sum, width) => sum + width, 0);
    const prepare = (cells, font) => widths.map((width, index) =>
      wrap(String(cells[index] ?? ''), font, cellSize, Math.max(width - pad * 2, 8)));
    const rowHeight = (cells) => Math.max(1, ...cells.map((lines) => lines.length)) * cellLine + 6;
    const headerLines = headerCells.length > 0 ? prepare(headerCells, headingFont) : null;
    const headerHeight = headerLines ? rowHeight(headerLines) : 0;
    const rows = bodyCells.map((row) => prepare(row, bodyFont));
    const heights = rows.map(rowHeight);

    ensure(30);

    if (block.caption) {
      page.drawText(String(block.caption), {
        x: MARGIN.left,
        y: y - 9,
        size: 8,
        font: headingFont,
        color: MUTED,
      });
      y -= 16;
    }

    const drawRow = (cells, font, height) => {
      let x = MARGIN.left;

      cells.forEach((lines, index) => {
        lines.forEach((line, lineIndex) => {
          page.drawText(line, {
            x: x + pad,
            y: y - 10 - lineIndex * cellLine,
            size: cellSize,
            font,
            color: INK,
          });
        });
        x += widths[index];
      });

      page.drawLine({
        start: { x: MARGIN.left, y: y - height },
        end: { x: MARGIN.left + total, y: y - height },
        thickness: 0.4,
        color: MUTED,
      });
      y -= height;
    };

    const drawHeaderRow = () => {
      if (headerLines) {
        drawRow(headerLines, headingFont, headerHeight);
      }
    };

    if (headerLines) {
      if (y - headerHeight < BODY_BOTTOM) {
        addPage();
      }
      drawHeaderRow();
    }

    for (let index = 0; index < rows.length; index++) {
      if (y - heights[index] < BODY_BOTTOM) {
        addPage();
        drawHeaderRow();
      }

      drawRow(rows[index], bodyFont, heights[index]);
    }

    y -= 10;
  }

  async function drawFigure(block) {
    const plate = await imageFor(block.asset_id ?? block.attrs?.asset_id);
    const targetWidth = 80 * MM;
    const targetHeight = (plate.height / plate.width) * targetWidth;

    ensure(targetHeight + 40);
    page.drawImage(plate, {
      x: MARGIN.left + (CONTENT_W - targetWidth) / 2,
      y: y - targetHeight,
      width: targetWidth,
      height: targetHeight,
    });
    y -= targetHeight + 6;

    if (block.caption) {
      page.drawText(String(block.caption), {
        x: MARGIN.left,
        y: y - 9,
        size: 8,
        font: headingFont,
        color: MUTED,
      });
    }
    y -= 18;
  }

  // Cover.
  page = doc.addPage([PAGE_W, PAGE_H]);
  pageIndex = 1;
  y = PAGE_H - MARGIN.top;
  noteY = CONTENT_BOTTOM + NOTE_BAND;
  const cover = fixture.cover ?? {};
  const center = (text, font, size, top, color = INK) => {
    if (!text) {
      return;
    }

    const width = font.widthOfTextAtSize(text, size);
    page.drawText(text, { x: (PAGE_W - width) / 2, y: top, size, font, color });
  };
  center(String(cover.kicker ?? '').toUpperCase(), headingFont, 9, PAGE_H - 90, MUTED);
  center(title, headingFont, 26, PAGE_H - 150);
  center(metadata.subtitle ? String(metadata.subtitle) : '', headingFont, 12, PAGE_H - 176, MUTED);
  center(metadata.author ? String(metadata.author) : '', bodyFont, 11, PAGE_H - 250);
  center(cover.note ? String(cover.note) : '', bodyFont, 8, PAGE_H - 320, MUTED);
  const coverLabel = '1';
  center(coverLabel, headingFont, 9, MARGIN.bottom / 2, MUTED);

  const fixtureNotes = {};
  let noteNumber = 0;

  for (const chapter of fixture.chapters) {
    for (const block of chapter.blocks) {
      if (block.type !== 'paragraph') {
        continue;
      }

      for (const match of block.text.matchAll(/\[\[([A-Z0-9]+)\]\]/g)) {
        noteNumber++;
        fixtureNotes[match[1]] = {
          number: noteNumber,
          body: block.notes?.[match[1]] ?? '',
        };
      }
    }
  }

  function drawSection(section) {
    addPage();
    drawTextBlock(section.title ?? '', {
      font: headingFont,
      size: 16,
      lineHeight: 21,
      gap: 10,
    });

    for (const paragraph of String(section.content ?? '').split(/\n+/)) {
      if (paragraph.trim() !== '') {
        drawParagraph(paragraph.trim());
      }
    }
  }

  currentChapterTitle = null;

  for (const section of fixture.sections ?? []) {
    if (section.area === 'front') {
      drawSection(section);
    }
  }

  for (const chapter of fixture.chapters) {
    currentChapterTitle = chapter.title;
    addPage();
    chapterPages[chapter.title] = pageIndex;

    if (chapter.id) {
      chapterPages['chapter:'.concat(chapter.id)] = pageIndex;
    }

    page.drawText(chapter.title, {
      x: MARGIN.left,
      y: y - HEADING_SIZE,
      size: HEADING_SIZE,
      font: headingFont,
      color: INK,
    });
    y -= HEADING_LINE + 8;

    let orderedNumber = 0;

    for (const block of chapter.blocks) {
      if (block.scene_id && scenePages['scene:'.concat(block.scene_id)] === undefined) {
        scenePages['scene:'.concat(block.scene_id)] = pageIndex;
      }

      if (block.type === 'paragraph') {
        orderedNumber = 0;
        drawParagraph(block.text ?? '');
      } else if (block.type === 'heading') {
        orderedNumber = 0;
        const level = Number(block.level ?? 2);
        const size = level <= 1 ? 16 : level === 2 ? 13 : 11;
        drawTextBlock(block.text ?? '', {
          font: headingFont,
          size,
          lineHeight: size + 5,
          gap: 8,
        });
      } else if (block.type === 'quote') {
        orderedNumber = 0;
        drawTextBlock(block.text ?? '', {
          indent: 10 * MM,
          color: MUTED,
          gap: 8,
        });
      } else if (block.type === 'list_item') {
        const ordered = block.list_type === 'ordered';
        orderedNumber = ordered ? orderedNumber + 1 : 0;
        const prefix = ordered ? `${orderedNumber}. ` : '• ';
        drawTextBlock(prefix + (block.text ?? ''), {
          indent: 6 * MM + Math.min(Number(block.depth ?? 0), 4) * 4 * MM,
        });
      } else if (block.type === 'page_break') {
        orderedNumber = 0;
        addPage();
      } else if (block.type === 'table') {
        orderedNumber = 0;
        drawTable(block);
      } else if (block.type === 'image') {
        orderedNumber = 0;
        await drawFigure(block);
      }
    }

    y -= 8;
  }

  currentChapterTitle = null;

  for (const section of fixture.sections ?? []) {
    if (section.area === 'back') {
      drawSection(section);
    }
  }

  // Index with resolved chapter/note pages.
  currentChapterTitle = null;
  addPage();
  page.drawText('Índice', {
    x: MARGIN.left,
    y: y - HEADING_SIZE,
    size: HEADING_SIZE,
    font: headingFont,
    color: INK,
  });
  y -= HEADING_LINE + 10;

  for (const entry of fixture.index) {
    const key = entry.page_ref ?? entry.ref;
    const target = key === 'Portada'
      ? 1
      : (chapterPages[key] ?? scenePages[key] ?? chapterPages[entry.ref] ?? noteRefPages[entry.ref] ?? null);

    if (target === null) {
      throw new Error(`Unresolved index target: ${entry.ref}`);
    }

    const label = `${entry.term} — p. ${target}`;
    ensure(BODY_LINE);
    page.drawText(label, {
      x: MARGIN.left,
      y: y - 10,
      size: 9,
      font: bodyFont,
      color: INK,
    });
    y -= 16;
  }

  finishPage();

  while (pendingNoteLines.length > 0) {
    addPage();
    finishPage();
  }

  return await doc.save();
}
