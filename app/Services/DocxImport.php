<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use ZipArchive;

class DocxImport
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_EXPANDED = 100 * 1024 * 1024;

    public const MAX_ENTRIES = 2000;

    public function preview(string $path, string $originalName): array
    {
        $bytes = (int) @filesize($path);

        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'El DOCX debe pesar entre 1 byte y 20 MiB.',
            ]);
        }

        $inspection = $this->inspectPackage($path);

        if (! $inspection['has_document']) {
            throw ValidationException::withMessages([
                'file' => 'El archivo no es un DOCX admitido.',
            ]);
        }

        $reader = IOFactory::createReader('Word2007');
        $reader->setImageLoading(false);

        try {
            $phpWord = $reader->load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo leer el DOCX de forma segura.',
            ]);
        }

        $inventory = [
            'headings' => [],
            'tables' => 0,
            'images' => $inspection['images'],
            'footnotes' => $phpWord->getFootnotes()->countItems(),
            'endnotes' => $phpWord->getEndnotes()->countItems(),
        ];
        $unsupported = $inspection['unsupported'];
        $blocks = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collect($element, $blocks, $inventory, $unsupported);
            }
        }

        $title = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Manuscrito';
        $chapters = $this->chapters($blocks, $title);
        $unsupportedList = [];

        foreach ($unsupported as $type => $count) {
            $unsupportedList[] = ['type' => $type, 'count' => $count, 'admitted' => false];
        }

        $losses = ['El formato enriquecido se convierte a texto plano.'];

        if ($inventory['tables'] > 0) {
            $losses[] = 'Las tablas se incorporan como texto plano.';
        }

        if ($inventory['images'] > 0) {
            $losses[] = 'Las imágenes no se incorporan en esta importación.';
        }

        if ($inventory['footnotes'] + $inventory['endnotes'] > 0) {
            $losses[] = 'Las notas al pie y al final no se incorporan en esta importación.';
        }

        if ($inspection['external_relationships'] > 0) {
            $losses[] = 'Se omitieron las relaciones externas; no se descargó ningún recurso remoto.';
        }

        if ($unsupportedList !== []) {
            $losses[] = 'Se omitió el contenido activo o no admitido; no se ejecutó.';
        }

        return [
            'source' => [
                'name' => basename($originalName),
                'bytes' => $bytes,
                'format' => 'docx',
                'encoding' => 'UTF-8',
            ],
            'chapters' => $chapters,
            'inventory' => $inventory,
            'unsupported' => $unsupportedList,
            'losses' => $losses,
        ];
    }

    private function inspectPackage(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => 'El DOCX no es un paquete ZIP válido.',
            ]);
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw ValidationException::withMessages([
                'file' => 'El DOCX contiene demasiados archivos.',
            ]);
        }

        $hasDocument = false;
        $expanded = 0;
        $images = 0;
        $external = 0;
        $unsupported = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafeName($name);

            $size = (int) ($zip->statIndex($i)['size'] ?? 0);
            $expanded += $size;

            if ($expanded > self::MAX_EXPANDED) {
                $zip->close();

                throw ValidationException::withMessages([
                    'file' => 'El DOCX supera el tamaño expandido permitido.',
                ]);
            }

            $lower = strtolower($name);

            if ($lower === 'word/document.xml') {
                $hasDocument = true;
            }

            if (str_starts_with($lower, 'word/media/') && ! str_ends_with($lower, '/')) {
                $images++;
            }

            if (str_ends_with($lower, '.xml') || str_ends_with($lower, '.rels')) {
                $xml = $zip->getFromName($name);

                if ($xml !== false && preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
                    $zip->close();

                    throw ValidationException::withMessages([
                        'file' => 'El DOCX contiene declaraciones XML no permitidas.',
                    ]);
                }

                if (str_ends_with($lower, '.rels')
                    && $xml !== false
                    && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $xml) === 1) {
                    $external++;
                }
            }

            if (str_ends_with($lower, 'vbaproject.bin') || str_contains($lower, 'macroenabled')) {
                $unsupported['macro'] = ($unsupported['macro'] ?? 0) + 1;
            } elseif (str_contains($lower, 'activex/')) {
                $unsupported['activex'] = ($unsupported['activex'] ?? 0) + 1;
            } elseif (str_contains($lower, '/embeddings/') || str_starts_with($lower, 'word/embeddings/')) {
                $unsupported['embedded_object'] = ($unsupported['embedded_object'] ?? 0) + 1;
            } elseif (str_contains($lower, 'customui')) {
                $unsupported['active_content'] = ($unsupported['active_content'] ?? 0) + 1;
            }
        }

        $contentTypes = strtolower((string) $zip->getFromName('[Content_Types].xml'));

        if (str_contains($contentTypes, 'macroenabled')) {
            $unsupported['macro'] = ($unsupported['macro'] ?? 0) + 1;
        }

        $zip->close();

        if ($external > 0) {
            $unsupported['external_relationship'] = ($unsupported['external_relationship'] ?? 0) + $external;
        }

        return [
            'has_document' => $hasDocument,
            'images' => $images,
            'external_relationships' => $external,
            'unsupported' => $unsupported,
        ];
    }

    private function assertSafeName(string $name): void
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            throw ValidationException::withMessages([
                'file' => 'El DOCX contiene rutas no permitidas.',
            ]);
        }
    }

    private function collect(mixed $element, array &$blocks, array &$inventory, array &$unsupported): void
    {
        if ($element instanceof Title) {
            $text = trim($this->elementText($element));

            if ($text !== '') {
                $blocks[] = ['heading' => $text];
                $inventory['headings'][] = $text;
            }

            return;
        }

        if ($element instanceof Table) {
            $inventory['tables']++;

            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $text = trim($this->elementText($cell));

                    if ($text !== '') {
                        $cells[] = $text;
                    }
                }

                if ($cells !== []) {
                    $blocks[] = ['text' => implode(' | ', $cells)];
                }
            }

            return;
        }

        if ($element instanceof Image) {
            $inventory['images']++;

            return;
        }

        $text = trim($this->elementText($element));

        if ($text !== '') {
            if ($this->isHeading($element, $text)) {
                $blocks[] = ['heading' => $text];
                $inventory['headings'][] = $text;
            } else {
                $blocks[] = ['text' => $text];
            }

            return;
        }

        $class = strtolower((new \ReflectionClass($element))->getShortName());

        if (in_array($class, [
            'oleobject', 'chart', 'shape', 'textbox', 'field', 'formfield',
            'sdt', 'toc', 'formula', 'checkbox', 'object',
        ], true)) {
            $unsupported[$class] = ($unsupported[$class] ?? 0) + 1;
        }

        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collect($child, $blocks, $inventory, $unsupported);
            }
        }
    }

    private function elementText(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if ($element instanceof Title) {
            return $this->elementText($element->getText());
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_string($text)) {
                return $text;
            }
        }

        if ($element instanceof AbstractContainer) {
            $text = '';

            foreach ($element->getElements() as $child) {
                $text .= $this->elementText($child);
            }

            return $text;
        }

        return '';
    }

    private function isHeading(mixed $element, string $text): bool
    {
        if (method_exists($element, 'getParagraphStyle')) {
            $style = $element->getParagraphStyle();
            $name = is_string($style)
                ? $style
                : (is_object($style) && method_exists($style, 'getStyleName') ? $style->getStyleName() : null);

            if (is_string($name)
                && preg_match('/(?:heading|title|t[íi]tulo)\s*[1-6]?/i', $name) === 1) {
                return true;
            }
        }

        return ImportText::looksLikeHeading($text);
    }

    private function chapters(array $blocks, string $defaultTitle): array
    {
        $chapters = [];
        $current = ['title' => $defaultTitle, 'lines' => []];

        foreach ($blocks as $block) {
            if (isset($block['heading'])) {
                if ($current['lines'] !== [] || $chapters !== []) {
                    $chapters[] = $current;
                    $current = ['title' => $block['heading'], 'lines' => []];
                } else {
                    $current['title'] = $block['heading'];
                }

                continue;
            }

            $current['lines'][] = $block['text'];
        }

        $chapters[] = $current;
        $chapters = array_values(array_filter($chapters, fn (array $chapter): bool => $chapter['lines'] !== []));

        if ($chapters === []) {
            $chapters[] = ['title' => $defaultTitle, 'lines' => []];
        }

        return array_map(fn (array $chapter): array => [
            'title' => $chapter['title'],
            'text' => implode("\n", $chapter['lines']),
        ], $chapters);
    }
}
