import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import { PDFDocument } from 'pdf-lib';
import { generatePdf } from '../layout.js';

const here = dirname(fileURLToPath(import.meta.url));
const fontsDir = join(here, '..', '..', '..', 'fonts');
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);
const jpeg = Buffer.from(
  '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
  'base64',
);

function pdfText(bytes, page = null) {
  const dir = mkdtempSync(join(tmpdir(), 'pdf-text-'));
  const file = join(dir, 'book.pdf');
  writeFileSync(file, bytes);

  try {
    const args = page === null
      ? ['-layout', file, '-']
      : ['-f', String(page), '-l', String(page), '-layout', file, '-'];
    const result = spawnSync('pdftotext', args, { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr || 'pdftotext no disponible');
    return result.stdout;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function pdfFontWarnings(bytes) {
  const dir = mkdtempSync(join(tmpdir(), 'pdf-fonts-'));
  const file = join(dir, 'book.pdf');
  writeFileSync(file, bytes);

  try {
    const result = spawnSync('pdffonts', [file], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr || 'pdffonts no disponible');
    return result.stderr ?? '';
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

const fixture = {
  metadata: { title: 'Prueba', subtitle: 'Fixture', author: 'Equipo', language: 'es' },
  cover: { kicker: 'Libro', note: '' },
  chapters: [
    {
      title: 'Capítulo uno',
      blocks: [
        {
          type: 'paragraph',
          text: 'Texto con nota[[F1]].',
          notes: { F1: 'Nota de prueba.' },
          note_numbers: { F1: 1 },
        },
        {
          type: 'table',
          caption: 'Tabla de prueba',
          headers: ['N.º', 'Valor'],
          rows: Array.from({ length: 6 }, (_, index) => [String(index + 1), 'Dato']),
        },
        { type: 'image', asset_id: 1, alt: 'Figura', caption: 'Pie de figura' },
      ],
    },
  ],
  index: [{ term: 'Capítulo uno', ref: 'Capítulo uno' }],
};

test('generates a PDF with cover, notes, table, image and index without print', async () => {
  const bytes = await generatePdf({
    fixture,
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: { 1: png },
  });

  assert.equal(new TextDecoder().decode(bytes.slice(0, 5)), '%PDF-');
  assert.ok(bytes.length > 5000);

  const document = await PDFDocument.load(bytes);
  assert.ok(document.getPageCount() >= 2);
});

test('embeds TTF and OTF-CFF files that Poppler can load', async () => {
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{ title: 'Capítulo uno', blocks: [{ type: 'paragraph', text: 'Texto de prueba con acentos: áéíóúñü.' }] }],
      index: [],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: {},
  });

  assert.doesNotMatch(
    pdfFontWarnings(bytes),
    /Embedded font file may be invalid|Couldn't create a font|Mismatch between font type/i,
  );
});

test('fails truthfully when a referenced image is missing', async () => {
  await assert.rejects(
    generatePdf({
      fixture,
      fonts: {
        body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
        heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
      },
      media: {},
    }),
    /Imagen no disponible/,
  );
});

test('renders headings, quotes, lists and explicit page breaks', async () => {
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{
        title: 'Capítulo uno',
        blocks: [
          { type: 'heading', level: 2, text: 'Escena visible' },
          { type: 'quote', text: 'Cita visible' },
          { type: 'list_item', text: 'Primer elemento', list_type: 'ordered' },
          { type: 'list_item', text: 'Segundo elemento', list_type: 'bullet' },
          { type: 'page_break' },
          { type: 'paragraph', text: 'Después del salto' },
        ],
      }],
      index: [{ term: 'Capítulo uno', ref: 'Capítulo uno' }],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: {},
  });

  const text = pdfText(bytes);

  assert.match(text, /Escena visible/);
  assert.match(text, /Cita visible/);
  assert.match(text, /1\. Primer elemento/);
  assert.match(text, /• Segundo elemento/);
  assert.match(text, /Después del salto/);
  assert.ok((await PDFDocument.load(bytes)).getPageCount() >= 3);
});

test('renders front and back matter around the chapters', async () => {
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      sections: [
        { area: 'front', title: 'Dedicatoria', content: 'Para quienes leen.' },
        { area: 'back', title: 'Epílogo', content: 'Fin de la historia.' },
      ],
      chapters: [{
        title: 'Capítulo uno',
        blocks: [{ type: 'paragraph', text: 'Cuerpo central.' }],
      }],
      index: [{ term: 'Capítulo uno', ref: 'Capítulo uno' }],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: {},
  });

  const text = pdfText(bytes);

  assert.match(text, /Dedicatoria/);
  assert.match(text, /Para quienes leen\./);
  assert.match(text, /Epílogo/);
  assert.match(text, /Fin de la historia\./);
  assert.ok(text.indexOf('Dedicatoria') < text.indexOf('Cuerpo central.'));
  assert.ok(text.indexOf('Cuerpo central.') < text.indexOf('Epílogo'));
});

test('resolves an index entry to the scene page instead of the chapter start', async () => {
  const filler = Array.from({ length: 60 }, (_, index) => ({
    type: 'paragraph',
    text: `Relleno ${index + 1}.`,
  }));

  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{
        id: 1,
        title: 'Capítulo uno',
        blocks: [...filler, { type: 'paragraph', text: 'Escena buscada.', scene_id: 10 }],
      }],
      index: [
        { term: 'Capítulo uno', ref: 'Capítulo uno', page_ref: 'chapter:1' },
        { term: 'Escena buscada', ref: 'Capítulo uno', page_ref: 'scene:10' },
      ],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: {},
  });

  const text = pdfText(bytes);
  const chapterPage = Number(text.match(/Capítulo uno — p\. (\d+)/)?.[1]);
  const scenePage = Number(text.match(/Escena buscada — p\. (\d+)/)?.[1]);

  assert.ok(chapterPage > 0);
  assert.ok(scenePage > chapterPage, `La escena debe apuntar a una página posterior (${scenePage} > ${chapterPage}).`);
});

test('resolves chapter and book running-head tokens by page parity', async () => {
  const long = Array.from({ length: 120 }, (_, index) => `Línea ${index} de la escena que continúa.`).join(' ');
  const fonts = {
    body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
    heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
  };
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [
        { title: 'Capítulo uno', blocks: [{ type: 'paragraph', text: long }] },
        { title: 'Capítulo dos', blocks: [{ type: 'paragraph', text: long }] },
      ],
      index: [],
      layout: { running_heads: { odd: 'chapter', even: 'book' } },
    },
    fonts,
    media: {},
  });

  assert.match(pdfText(bytes, 2), /Prueba/, 'la página par debe mostrar el título del libro');
  assert.match(pdfText(bytes, 3), /Capítulo uno/, 'la página impar debe mostrar el capítulo activo');
  assert.doesNotMatch(pdfText(bytes, 3), /Prueba/, 'la página impar no repite el título del libro como cabecera');
});

test('keeps a literal running head', async () => {
  const long = Array.from({ length: 120 }, (_, index) => `Línea ${index} de la escena que continúa.`).join(' ');
  const fonts = {
    body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
    heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
  };
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{ title: 'Capítulo uno', blocks: [{ type: 'paragraph', text: long }] }],
      index: [],
      layout: { running_heads: { odd: 'La imprenta y la memoria', even: 'La imprenta y la memoria' } },
    },
    fonts,
    media: {},
  });

  assert.match(pdfText(bytes, 3), /La imprenta y la memoria/);
  assert.doesNotMatch(pdfText(bytes, 3), /chapter/i);
});

test('does not leak the internal fixture header into the book', async () => {
  const bytes = await generatePdf({
    fixture,
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: { 1: png },
  });

  assert.doesNotMatch(pdfText(bytes), /Prueba PDF de maquetación/);
});

test('embeds JPEG images without a PNG conversion', async () => {
  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{
        title: 'Capítulo uno',
        blocks: [{ type: 'image', asset_id: 2, alt: 'Foto', caption: 'Foto' }],
      }],
      index: [{ term: 'Capítulo uno', ref: 'Capítulo uno' }],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: { 2: jpeg },
  });

  assert.equal(new TextDecoder().decode(bytes.slice(0, 5)), '%PDF-');
});

test('lays out tables with more than four columns', async () => {
  const cells = Array.from({ length: 6 }, (_, index) => `Columna ${index + 1}`);

  const bytes = await generatePdf({
    fixture: {
      ...fixture,
      chapters: [{
        title: 'Capítulo uno',
        blocks: [{ type: 'table', caption: 'Tabla ancha', headers: cells, rows: [cells] }],
      }],
      index: [{ term: 'Capítulo uno', ref: 'Capítulo uno' }],
    },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: {},
  });

  assert.match(pdfText(bytes), /Columna 6/);
});

test('accepts metadata without author or subtitle', async () => {
  const bytes = await generatePdf({
    fixture: { ...fixture, metadata: { title: 'Solo título', language: 'es' } },
    fonts: {
      body: readFileSync(join(fontsDir, 'CrimsonText-Regular.ttf')),
      heading: readFileSync(join(fontsDir, 'SourceSerif4-Regular.otf')),
    },
    media: { 1: png },
  });

  assert.equal(new TextDecoder().decode(bytes.slice(0, 5)), '%PDF-');
});
