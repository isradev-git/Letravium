<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

class BookExporter
{
    public function __construct(private ImageMetadataStripper $images) {}

    public function warnings(string $format): array
    {
        return match ($format) {
            'txt' => ['TXT omite formato visual, imágenes y tablas que no puedan representarse con fidelidad.'],
            'docx' => [
                'La inclusión de archivos de fuente en DOCX no está garantizada.',
                'La paginación puede variar al abrir el documento en otros procesadores.',
            ],
            'epub' => [
                'EPUBCheck no está disponible localmente (no hay Java); la validación estructural no lo sustituye.',
                'El lector puede sustituir la fuente y cambiar la disposición.',
            ],
            'pdf' => [
                'El PDF se genera en el navegador con las bibliotecas empaquetadas; no se usa la impresión del sistema.',
                'La validación visual y estructural del PDF integrado sigue pendiente (G03 abierto).',
                'Las fuentes e imágenes del libro se descargan antes de generar el archivo.',
            ],
            default => [],
        };
    }

    public function txt(array $manifest): string
    {
        $metadata = $manifest['metadata'] ?? [];
        $lines = [];

        $lines[] = (string) ($metadata['title'] ?? '');
        if (! empty($metadata['subtitle'])) {
            $lines[] = (string) $metadata['subtitle'];
        }
        if (! empty($metadata['author'])) {
            $lines[] = (string) $metadata['author'];
        }
        foreach (['edition', 'publisher', 'rights', 'isbn'] as $key) {
            if (! empty($metadata[$key])) {
                $lines[] = ucfirst($key).': '.$metadata[$key];
            }
        }

        foreach ($manifest['sections'] ?? [] as $section) {
            if (($section['area'] ?? 'front') !== 'front') {
                continue;
            }
            $lines[] = '';
            $lines[] = mb_strtoupper((string) ($section['title'] ?: $section['type']));
            $lines[] = '';
            $lines[] = (string) ($section['content'] ?? '');
        }

        foreach ($manifest['chapters'] ?? [] as $chapter) {
            $lines[] = '';
            $lines[] = str_repeat('=', 72);
            $lines[] = (string) ($chapter['title'] ?: 'Capítulo');
            $lines[] = str_repeat('=', 72);
            $lines[] = '';

            foreach (($chapter['blocks'] ?? []) as $block) {
                $text = (string) ($block['text'] ?? '');
                $type = $block['type'] ?? 'paragraph';

                if (in_array($type, ['scene_break', 'section_break'], true)) {
                    $lines[] = '* * *';
                } elseif ($type === 'page_break') {
                    $lines[] = "\f";
                } elseif ($type === 'heading') {
                    $lines[] = '';
                    $lines[] = $text;
                } elseif ($type === 'list_item') {
                    $lines[] = ($block['list_type'] ?? 'bullet') === 'ordered' ? '1. '.$text : '• '.$text;
                } elseif ($type === 'table') {
                    foreach (($block['rows'] ?? []) as $row) {
                        $lines[] = implode(' | ', array_map(
                            fn ($cell) => (string) ($cell['text'] ?? ''),
                            $row['cells'] ?? [],
                        ));
                    }
                } elseif ($type === 'image') {
                    $lines[] = (string) ($block['attrs']['caption'] ?? $block['attrs']['alt'] ?? '[Imagen]');
                } else {
                    $lines[] = $text;
                    $lines[] = '';
                }
            }
        }

        $notes = $manifest['notes'] ?? [];
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = str_repeat('=', 72);
            $lines[] = 'NOTAS';
            $lines[] = str_repeat('=', 72);

            foreach ($notes as $note) {
                $kind = ($note['kind'] ?? 'foot') === 'end' ? 'Final' : 'Pie';
                $lines[] = "[{$kind} {$note['number']}] {$note['text']}";
            }
        }

        foreach ($manifest['sections'] ?? [] as $section) {
            if (($section['area'] ?? 'front') !== 'back') {
                continue;
            }
            $lines[] = '';
            $lines[] = mb_strtoupper((string) ($section['title'] ?: $section['type']));
            $lines[] = (string) ($section['content'] ?? '');
        }

        $lines[] = '';
        $lines[] = 'AVISO: esta exportación TXT omite formato visual, imágenes y tablas que no se representan con fidelidad.';

        return implode("\n", $lines)."\n";
    }

    public function docx(array $manifest): string
    {
        $word = new PhpWord;
        [$bodyFamily, $headingFamily] = $this->fontSet($manifest);
        $word->setDefaultFontName($bodyFamily);
        $word->setDefaultFontSize(11);

        foreach ([1 => 18, 2 => 15, 3 => 13, 4 => 12, 5 => 11, 6 => 11] as $level => $size) {
            $word->addTitleStyle($level, ['name' => $headingFamily, 'size' => $size, 'bold' => true]);
        }

        $word->addParagraphStyle('CuerpoLibro', [
            'alignment' => 'both',
            'spaceAfter' => 120,
        ]);

        $profile = $manifest['profile'] ?? [];
        [$width, $height] = $this->pageMillimetres($profile['page_size'] ?? null);
        $margins = $profile['margins'] ?? [];

        $section = $word->addSection([
            'pageSizeW' => (int) round($width * 56.6929),
            'pageSizeH' => (int) round($height * 56.6929),
            'marginLeft' => (int) round($this->millimetres($margins['left'] ?? 16) * 56.6929),
            'marginRight' => (int) round($this->millimetres($margins['right'] ?? 16) * 56.6929),
            'marginTop' => (int) round($this->millimetres($margins['top'] ?? 18) * 56.6929),
            'marginBottom' => (int) round($this->millimetres($margins['bottom'] ?? 22) * 56.6929),
        ]);

        $metadata = $manifest['metadata'] ?? [];
        $media = collect($manifest['resources']['media'] ?? [])->keyBy('id');
        $section->addTitle((string) ($metadata['title'] ?? ''), 1);
        if (! empty($metadata['subtitle'])) {
            $section->addText((string) $metadata['subtitle'], ['italic' => true], ['alignment' => 'center']);
        }
        if (! empty($metadata['author'])) {
            $section->addText((string) $metadata['author'], [], ['alignment' => 'center']);
        }

        foreach ($manifest['sections'] ?? [] as $front) {
            if (($front['area'] ?? 'front') === 'front') {
                $section->addTitle((string) ($front['title'] ?: $front['type']), 2);
                $section->addText((string) ($front['content'] ?? ''), [], 'CuerpoLibro');
            }
        }

        foreach ($manifest['chapters'] ?? [] as $chapter) {
            $section->addPageBreak();
            $section->addTitle((string) ($chapter['title'] ?: 'Capítulo'), 1);
            $chapterNotes = collect($chapter['notes'] ?? [])->keyBy('id')->all();

            foreach (($chapter['blocks'] ?? []) as $block) {
                $type = $block['type'] ?? 'paragraph';
                $text = (string) ($block['text'] ?? '');

                if ($type === 'heading') {
                    $section->addTitle($text, min(6, max(2, (int) ($block['level'] ?? 2))));
                } elseif (in_array($type, ['scene_break', 'section_break'], true)) {
                    $section->addText('* * *', [], ['alignment' => 'center']);
                } elseif ($type === 'page_break') {
                    $section->addPageBreak();
                } elseif ($type === 'list_item') {
                    $run = $section->addListItemRun((int) ($block['depth'] ?? 0));
                    $this->docxText($run, $text, $block['marks'] ?? [], $chapterNotes);
                } elseif ($type === 'table') {
                    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999']);

                    foreach (($block['rows'] ?? []) as $row) {
                        $table->addRow();

                        foreach (($row['cells'] ?? []) as $cell) {
                            $table->addCell()->addText(
                                (string) ($cell['text'] ?? ''),
                                ($row['header'] ?? false) ? ['bold' => true] : [],
                            );
                        }
                    }
                } elseif ($type === 'image') {
                    $asset = $media[(int) ($block['attrs']['asset_id'] ?? 0)] ?? null;

                    if ($asset
                        && ! empty($asset['exists'])
                        && ! empty($asset['path'])
                        && Storage::disk('local')->exists((string) $asset['path'])) {
                        $imageWidth = (int) ($asset['metadata']['width'] ?? 0);
                        $imageHeight = (int) ($asset['metadata']['height'] ?? 0);
                        $options = ['width' => 400];

                        if ($imageWidth > 0 && $imageHeight > 0) {
                            $options['height'] = (int) round(400 * $imageHeight / $imageWidth);
                        }

                        $prepared = $this->images->prepare(
                            Storage::disk('local')->get((string) $asset['path']),
                            (string) ($asset['mime'] ?? ''),
                        );
                        $section->addImage($prepared['bytes'], $options);
                    }

                    $section->addText((string) ($block['attrs']['caption'] ?? $block['attrs']['alt'] ?? '[Imagen]'), ['italic' => true]);
                } elseif ($type === 'quote') {
                    $run = $section->addTextRun(['indent' => 720]);
                    $this->docxText($run, $text, $block['marks'] ?? [], $chapterNotes, ['italic' => true]);
                } else {
                    $run = $section->addTextRun('CuerpoLibro');
                    $this->docxText($run, $text, $block['marks'] ?? [], $chapterNotes);
                }
            }
        }

        $tables = ($manifest['navigation']['tables'] ?? []);
        $figures = ($manifest['navigation']['figures'] ?? []);

        if ($tables !== [] || $figures !== []) {
            $section->addPageBreak();
            $section->addTitle('Listas de ilustraciones y tablas', 2);
            $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999']);
            $table->addRow();
            $table->addCell(1600)->addText('Tipo', ['bold' => true]);
            $table->addCell(7000)->addText('Pie', ['bold' => true]);

            foreach ($figures as $entry) {
                $table->addRow();
                $table->addCell(1600)->addText('Figura');
                $table->addCell(7000)->addText((string) ($entry['caption'] ?? $entry['label'] ?? ''));
            }
            foreach ($tables as $entry) {
                $table->addRow();
                $table->addCell(1600)->addText('Tabla');
                $table->addCell(7000)->addText((string) ($entry['caption'] ?? $entry['label'] ?? ''));
            }
        }

        foreach ($manifest['sections'] ?? [] as $back) {
            if (($back['area'] ?? 'front') === 'back') {
                $section->addPageBreak();
                $section->addTitle((string) ($back['title'] ?: $back['type']), 2);
                $section->addText((string) ($back['content'] ?? ''), [], 'CuerpoLibro');
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function epub(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epub');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            unlink($path);
            throw new \RuntimeException('No se pudo crear el EPUB.');
        }

        $metadata = $manifest['metadata'] ?? [];
        $language = (string) ($metadata['language'] ?: 'es');
        $title = (string) ($metadata['title'] ?? '');
        $identifier = 'urn:app-escritor:novel:'.($manifest['novel']['id'] ?? '0');

        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            .'<rootfiles><rootfile full-path="OEBPS/package.opf" media-type="application/oebps-package+xml"/></rootfiles>'
            .'</container>');

        $chapterFiles = [];
        foreach ($manifest['chapters'] ?? [] as $index => $chapter) {
            $chapterFiles[(int) $chapter['id']] = 'chapter-'.($index + 1).'.xhtml';
        }

        [$bodyFamily, $headingFamily, $embeddedFonts] = $this->fontSet($manifest);
        $fontFaces = [];
        $manifestItems = [];

        foreach ($embeddedFonts as $fontId => $font) {
            $fontPath = (string) ($font['path'] ?? '');
            $extension = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));

            if (! in_array($extension, ['ttf', 'otf'], true)
                || ! Storage::disk('local')->exists($fontPath)) {
                continue;
            }

            $fontFile = 'fonts/'.$fontId.'.'.$extension;
            $zip->addFromString('OEBPS/'.$fontFile, Storage::disk('local')->get($fontPath));
            $manifestItems[] = '<item id="font'.$fontId.'" href="'.$fontFile.'" media-type="font/'.$extension.'"/>';
            $family = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) ($font['family'] ?? ''));
            $format = $extension === 'otf' ? 'opentype' : 'truetype';
            $fontFaces[] = "@font-face { font-family: '{$family}'; src: url('{$fontFile}') format('{$format}'); }";
        }

        $manifestItems = array_merge([
            '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>',
            '<item id="css" href="style.css" media-type="text/css"/>',
            '<item id="notes" href="notes.xhtml" media-type="application/xhtml+xml"/>',
        ], $manifestItems);
        $spineItems = [];
        $mediaFiles = [];
        $frontSpine = [];
        $backSpine = [];
        $sectionFiles = ['front' => [], 'back' => []];
        $sectionNumbers = ['front' => 0, 'back' => 0];

        foreach (($manifest['sections'] ?? []) as $section) {
            $area = ($section['area'] ?? 'front') === 'back' ? 'back' : 'front';
            $number = ++$sectionNumbers[$area];
            $file = $area.'-'.$number.'.xhtml';
            $title = (string) ($section['title'] ?: $section['type'] ?? '');

            $zip->addFromString('OEBPS/'.$file, $this->sectionXhtml($section, $language));
            $manifestItems[] = '<item id="'.$area.$number.'" href="'.$file.'" media-type="application/xhtml+xml"/>';
            $itemref = '<itemref idref="'.$area.$number.'"/>';

            if ($area === 'front') {
                $frontSpine[] = $itemref;
            } else {
                $backSpine[] = $itemref;
            }

            $sectionFiles[$area][] = ['file' => $file, 'title' => $title];
        }

        foreach (($manifest['resources']['media'] ?? []) as $asset) {
            $mediaId = (int) ($asset['id'] ?? 0);

            if ($mediaId < 1
                || empty($asset['exists'])
                || empty($asset['path'])
                || ! Storage::disk('local')->exists((string) $asset['path'])) {
                continue;
            }

            $prepared = $this->images->prepare(
                Storage::disk('local')->get((string) $asset['path']),
                (string) ($asset['mime'] ?? ''),
            );
            $extension = $prepared['extension'] ?? strtolower(pathinfo((string) (($asset['original_name'] ?? '') ?: $asset['path']), PATHINFO_EXTENSION) ?: 'bin');
            $mediaFile = 'media/'.$mediaId.'.'.$extension;
            $mediaFiles[$mediaId] = $mediaFile;
            $zip->addFromString('OEBPS/'.$mediaFile, $prepared['bytes']);
            $manifestItems[] = '<item id="img'.$mediaId.'" href="'.$mediaFile.'" media-type="'
                .$this->xml($prepared['mime'] ?: (string) ($asset['mime'] ?? 'application/octet-stream')).'"/>';
        }

        foreach ($manifest['chapters'] ?? [] as $index => $chapter) {
            $file = $chapterFiles[(int) $chapter['id']];
            $zip->addFromString('OEBPS/'.$file, $this->chapterXhtml($chapter, $language, $mediaFiles));
            $manifestItems[] = '<item id="chap'.($index + 1).'" href="'.$file.'" media-type="application/xhtml+xml"/>';
            $spineItems[] = '<itemref idref="chap'.($index + 1).'"/>';
        }

        $spineItems = array_merge($frontSpine, $spineItems, $backSpine);
        $spineItems[] = '<itemref idref="notes"/>';

        $zip->addFromString('OEBPS/notes.xhtml', $this->notesXhtml($manifest, $chapterFiles, $language));
        $zip->addFromString('OEBPS/nav.xhtml', $this->navigationXhtml($manifest, $chapterFiles, $language, $sectionFiles));
        $zip->addFromString('OEBPS/style.css', $this->epubCss($fontFaces, $bodyFamily, $headingFamily));

        $package = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="'.$this->xml($language).'">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:identifier id="bookid">'.$this->xml($identifier).'</dc:identifier>'
            .'<dc:title>'.$this->xml($title).'</dc:title>'
            .'<dc:language>'.$this->xml($language).'</dc:language>';

        foreach (['author' => 'creator', 'description' => 'description', 'rights' => 'rights', 'publisher' => 'publisher'] as $key => $tag) {
            if (! empty($metadata[$key])) {
                $package .= '<dc:'.$tag.'>'.$this->xml((string) $metadata[$key]).'</dc:'.$tag.'>';
            }
        }

        $package .= '<meta property="dcterms:modified">'.gmdate('Y-m-d\TH:i:s\Z').'</meta>'
            .'</metadata><manifest>'.implode('', $manifestItems).'</manifest>'
            .'<spine>'.implode('', $spineItems).'</spine></package>';

        $zip->addFromString('OEBPS/package.opf', $package);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function epubImage(array $block, array $mediaFiles): string
    {
        $assetId = (int) ($block['attrs']['asset_id'] ?? 0);
        $caption = (string) ($block['attrs']['caption'] ?? '');
        $alt = (string) ($block['attrs']['alt'] ?? '');
        $file = $mediaFiles[$assetId] ?? null;

        if ($file !== null) {
            return '<figure class="imagen"><img src="'.$this->xml($file).'" alt="'.$this->xml($alt).'"/>'
                .($caption !== '' ? '<figcaption>'.$this->xml($caption).'</figcaption>' : '')
                .'</figure>';
        }

        return '<figure class="imagen"><p>'.$this->xml($caption !== '' ? $caption : ($alt !== '' ? $alt : '[Imagen]')).'</p></figure>';
    }

    private function epubTable(array $block): string
    {
        $html = '<table>';

        foreach (($block['rows'] ?? []) as $row) {
            $html .= '<tr>';

            foreach (($row['cells'] ?? []) as $cell) {
                $tag = ($row['header'] ?? false) ? 'th' : 'td';
                $html .= '<'.$tag.'>'.$this->xml((string) ($cell['text'] ?? '')).'</'.$tag.'>';
            }

            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function chapterXhtml(array $chapter, string $language, array $mediaFiles = []): string
    {
        $title = (string) ($chapter['title'] ?: 'Capítulo');
        $notes = collect($chapter['notes'] ?? [])->keyBy('id')->all();
        $html = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'.$this->xml($language).'">'
            .'<head><title>'.$this->xml($title).'</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
            .'<section epub:type="chapter"><h1 id="chapter-'.$chapter['id'].'">'.$this->xml($title).'</h1>';

        foreach (($chapter['blocks'] ?? []) as $block) {
            $text = $this->epubText((string) ($block['text'] ?? ''), $block['marks'] ?? [], $notes);
            $type = $block['type'] ?? 'paragraph';
            $level = min(6, max(2, (int) ($block['level'] ?? 2)));

            $html .= match ($type) {
                'heading' => '<h'.$level.' id="block-'.$this->xml((string) ($block['id'] ?? '')).'">'.$text.'</h'.$level.'>',
                'scene_break', 'section_break' => '<p class="separador">* * *</p>',
                'page_break' => '<hr class="page-break"/>',
                'list_item' => '<p class="list-item">'.(($block['list_type'] ?? 'bullet') === 'ordered' ? '1. ' : '• ').$text.'</p>',
                'table' => $this->epubTable($block),
                'image' => $this->epubImage($block, $mediaFiles),
                'quote' => '<blockquote><p>'.$text.'</p></blockquote>',
                default => '<p>'.$text.'</p>',
            };
        }

        return $html.'</section></body></html>';
    }

    private function sectionXhtml(array $section, string $language): string
    {
        $title = (string) ($section['title'] ?: $section['type'] ?? '');
        $area = ($section['area'] ?? 'front') === 'back' ? 'backmatter' : 'frontmatter';
        $html = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'.$this->xml($language).'">'
            .'<head><title>'.$this->xml($title).'</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
            .'<section epub:type="'.$area.'"><h1>'.$this->xml($title).'</h1>';

        foreach (preg_split('/\n+/', (string) ($section['content'] ?? '')) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $html .= '<p>'.$this->xml(trim($paragraph)).'</p>';
            }
        }

        return $html.'</section></body></html>';
    }

    private function epubText(string $text, array $marks, array $notes): string
    {
        $references = [];

        foreach ($marks as $mark) {
            if (($mark['type'] ?? null) !== 'footnote_reference') {
                continue;
            }

            $noteId = (int) ($mark['attrs']['note_id'] ?? 0);
            $note = $notes[$noteId] ?? null;

            if ($note === null) {
                continue;
            }

            $position = isset($mark['end'])
                ? (int) $mark['end']
                : (isset($mark['start']) ? (int) $mark['start'] : mb_strlen($text));
            $references[$position][] = $note;
        }

        ksort($references);

        if ($references === []) {
            return $this->xml($text);
        }

        $html = '';
        $cursor = 0;

        foreach ($references as $position => $notesAtPosition) {
            $position = max($cursor, min($position, mb_strlen($text)));
            $html .= $this->xml(mb_substr($text, $cursor, $position - $cursor));

            foreach ($notesAtPosition as $note) {
                $html .= '<sup><a epub:type="noteref" href="notes.xhtml#fn-'.$note['id'].'" id="fnref-'.$note['id'].'">['.$note['number'].']</a></sup>';
            }

            $cursor = $position;
        }

        return $html.$this->xml(mb_substr($text, $cursor));
    }

    private function notesXhtml(array $manifest, array $chapterFiles, string $language): string
    {
        $html = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'.$this->xml($language).'">'
            .'<head><title>Notas</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
            .'<section epub:type="endnotes"><h1>Notas</h1>';

        foreach (($manifest['notes'] ?? []) as $note) {
            $html .= '<aside epub:type="footnote" id="fn-'.$note['id'].'"><p>'
                .$this->xml((string) $note['text']);

            if (! empty($note['chapter_id']) && isset($chapterFiles[(int) $note['chapter_id']])) {
                $html .= ' <a class="volver" href="'.$chapterFiles[(int) $note['chapter_id']]
                    .'#fnref-'.$note['id'].'">&#8617;</a>';
            }

            $html .= '</p></aside>';
        }

        return $html.'</section></body></html>';
    }

    private function navigationXhtml(array $manifest, array $chapterFiles, string $language, array $sectionFiles = []): string
    {
        $html = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'.$this->xml($language).'">'
            .'<head><title>Índice</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
            .'<nav epub:type="toc" id="toc"><h1>Índice</h1><ol>';

        foreach (($sectionFiles['front'] ?? []) as $section) {
            $html .= '<li><a href="'.$this->xml($section['file']).'">'.$this->xml($section['title']).'</a></li>';
        }

        foreach ($manifest['chapters'] ?? [] as $chapter) {
            $file = $chapterFiles[(int) $chapter['id']];
            $html .= '<li><a href="'.$file.'#chapter-'.$chapter['id'].'">'
                .$this->xml((string) ($chapter['title'] ?: 'Capítulo')).'</a>';

            $headings = [];
            foreach (($chapter['blocks'] ?? []) as $block) {
                if (($block['type'] ?? null) === 'heading') {
                    $headings[] = $block;
                }
            }

            if ($headings !== []) {
                $html .= '<ol>';
                foreach ($headings as $heading) {
                    $html .= '<li><a href="'.$file.'#block-'.$this->xml((string) ($heading['id'] ?? ''))
                        .'">'.$this->xml((string) ($heading['text'] ?? '')).'</a></li>';
                }
                $html .= '</ol>';
            }

            $html .= '</li>';
        }

        foreach (($sectionFiles['back'] ?? []) as $section) {
            $html .= '<li><a href="'.$this->xml($section['file']).'">'.$this->xml($section['title']).'</a></li>';
        }

        return $html.'</ol></nav></body></html>';
    }

    private function epubCss(array $fontFaces, string $bodyFamily, string $headingFamily): string
    {
        $faces = $fontFaces !== [] ? implode("\n", $fontFaces)."\n" : '';
        $body = $this->cssFontStack($bodyFamily);
        $heading = $this->cssFontStack($headingFamily);

        return $faces
            ."body { font-family: {$body}; line-height: 1.5; margin: 1em; }"
            ." h1, h2, h3, h4, h5, h6 { font-family: {$heading}; }"
            .' h1 { text-align: center; } p { text-align: justify; }'
            .' .separador { text-align: center; } .nota-referencia { text-align: right; }'
            .' .volver { text-decoration: none; }';
    }

    private function cssFontStack(string $family): string
    {
        if ($family === '' || strcasecmp($family, 'Georgia') === 0) {
            return 'Georgia, serif';
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $family)."', Georgia, serif";
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, array<string, mixed>>}
     */
    private function fontSet(array $manifest): array
    {
        $resources = [];

        foreach (($manifest['resources']['fonts'] ?? []) as $font) {
            $resources[(int) ($font['id'] ?? 0)] = $font;
        }

        $bodyId = $this->fontId($manifest['profile']['fonts'] ?? [], ['body', 'texto', 'cuerpo']);
        $headingId = $this->fontId($manifest['profile']['fonts'] ?? [], ['head', 'title', 'titulo', 'display']);
        $firstId = null;

        foreach ($resources as $id => $font) {
            if ($firstId === null && ! empty($font['exists'])) {
                $firstId = $id;
            }
        }

        $bodyId ??= $firstId;
        $headingId ??= $bodyId;

        $body = $this->fontFamily($resources, $bodyId);
        $heading = $this->fontFamily($resources, $headingId);
        $body ??= $heading;
        $heading ??= $body;

        $embedded = [];

        foreach ([$bodyId, $headingId] as $id) {
            if ($id !== null && ! empty($resources[$id]['exists'])) {
                $embedded[(int) $id] = $resources[$id];
            }
        }

        return [$body ?? 'Georgia', $heading ?? 'Georgia', $embedded];
    }

    private function fontFamily(array $resources, ?int $id): ?string
    {
        if ($id === null || empty($resources[$id]['exists'])) {
            return null;
        }

        $family = trim((string) ($resources[$id]['family'] ?? ''));

        return $family !== '' ? $family : null;
    }

    private function fontId(mixed $value, array $needles): ?int
    {
        $found = null;

        array_walk_recursive($value, function ($item, $key) use (&$found, $needles): void {
            if ($found !== null || ! is_string($key)) {
                return;
            }

            $key = strtolower($key);

            if (! is_int($item) && ! ctype_digit((string) $item)) {
                return;
            }

            foreach ($needles as $needle) {
                if (str_contains($key, $needle)) {
                    $found = (int) $item;

                    return;
                }
            }
        });

        return $found;
    }

    private function docxText($run, string $text, array $marks, array $notes, ?array $fontStyle = null): void
    {
        $references = [];

        foreach ($marks as $mark) {
            if (($mark['type'] ?? null) !== 'footnote_reference') {
                continue;
            }

            $noteId = (int) ($mark['attrs']['note_id'] ?? 0);
            $note = $notes[$noteId] ?? null;

            if ($note === null) {
                continue;
            }

            $position = isset($mark['end'])
                ? (int) $mark['end']
                : (isset($mark['start']) ? (int) $mark['start'] : mb_strlen($text));
            $references[$position][] = $note;
        }

        ksort($references);

        if ($references === []) {
            $run->addText($text, $fontStyle);

            return;
        }

        $cursor = 0;

        foreach ($references as $position => $notesAtPosition) {
            $position = max($cursor, min($position, mb_strlen($text)));

            if ($position > $cursor) {
                $run->addText(mb_substr($text, $cursor, $position - $cursor), $fontStyle);
            }

            foreach ($notesAtPosition as $note) {
                $element = ($note['kind'] ?? 'foot') === 'end'
                    ? $run->addEndnote()
                    : $run->addFootnote();
                $element->addText((string) $note['text']);
            }

            $cursor = $position;
        }

        if ($cursor < mb_strlen($text)) {
            $run->addText(mb_substr($text, $cursor), $fontStyle);
        }
    }

    private function pageMillimetres(?string $pageSize): array
    {
        if ($pageSize && preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/', $pageSize, $match)) {
            return [(float) $match[1], (float) $match[2]];
        }

        return [148.0, 210.0];
    }

    private function millimetres(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/(\d+(?:\.\d+)?)/', $value, $match)) {
            return (float) $match[1];
        }

        return 0.0;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
