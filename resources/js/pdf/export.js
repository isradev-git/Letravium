import defaultBody from '../../fonts/CrimsonText-Regular.ttf?url';
import defaultHeading from '../../fonts/SourceSerif4-Regular.otf?url';
import { generatePdf } from './layout.js';

const inputElement = document.getElementById('pdf-input');
const resourcesElement = document.getElementById('pdf-resources');
const statusElement = document.getElementById('pdf-status');
const downloadElement = document.getElementById('pdf-download');

async function bytesFrom(url) {
  const response = await fetch(url, { credentials: 'same-origin' });

  if (!response.ok) {
    throw new Error(`No se pudo cargar un recurso (${response.status}).`);
  }

  return new Uint8Array(await response.arrayBuffer());
}

async function main() {
  if (!inputElement || !resourcesElement) {
    return;
  }

  const fixture = JSON.parse(inputElement.textContent);
  const resources = JSON.parse(resourcesElement.textContent);

  statusElement.textContent = 'Preparando el PDF…';

  const bodyUrl = resources.fonts.body ?? defaultBody;
  const headingUrl = resources.fonts.heading ?? resources.fonts.body ?? defaultHeading;

  const fonts = {
    body: await bytesFrom(bodyUrl),
    heading: await bytesFrom(headingUrl),
  };

  const media = {};

  for (const [id, url] of Object.entries(resources.media ?? {})) {
    media[Number(id)] = await bytesFrom(url);
  }

  const bytes = await generatePdf({ fixture, fonts, media });
  const blob = new Blob([bytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);

  downloadElement.href = url;
  downloadElement.download = resources.filename ?? 'libro.pdf';
  downloadElement.hidden = false;
  statusElement.textContent = `PDF listo: ${bytes.length} bytes.`;
  window.__pdfDone = { bytes: bytes.length, header: String.fromCharCode(...bytes.slice(0, 5)) };

  const link = document.createElement('a');
  link.href = url;
  link.download = downloadElement.download;
  document.body.appendChild(link);
  link.click();
  link.remove();
}

main().catch((error) => {
  if (statusElement) {
    statusElement.textContent = `No se pudo generar el PDF: ${error.message}`;
  }

  window.__pdfDone = { error: String(error) };
});
