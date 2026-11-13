<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class HtmlSanitizer
{
    public const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sup', 'sub', 'a',
    ];

    private const BLOCK_TAGS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot',
        'tr', 'td', 'th', 'blockquote', 'pre', 'figure', 'figcaption', 'address',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
    ];

    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'link', 'meta', 'base', 'img', 'picture',
        'source', 'video', 'audio', 'canvas', 'svg', 'math', 'template', 'noscript',
    ];

    public function sanitize(string $html): array
    {
        $document = $this->parse(str_replace("\0", '', $html));

        if (! $document) {
            return ['blocks' => [], 'dropped' => ['html']];
        }

        $body = $document->getElementsByTagName('body')->item(0) ?? $document;
        $blocks = [];
        $dropped = [];

        $this->collectBlocks($body, $blocks, $dropped);

        return ['blocks' => $blocks, 'dropped' => array_values(array_unique($dropped))];
    }

    private function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } catch (\Throwable) {
            $loaded = false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function collectBlocks(DOMNode $parent, array &$blocks, array &$dropped): void
    {
        $inline = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $this->appendText($child->nodeValue, [], $inline);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $this->drop($dropped, $tag);

                continue;
            }

            if ($tag === 'br') {
                $inline[] = ['text' => "\n", 'marks' => []];

                continue;
            }

            if ($tag === 'p') {
                $this->flush($inline, $blocks);
                $paragraph = [];
                $this->collectInline($child, [], $paragraph, $dropped);
                $this->flush($paragraph, $blocks);

                continue;
            }

            if (in_array($tag, self::BLOCK_TAGS, true)) {
                $this->flush($inline, $blocks);
                $this->drop($dropped, $tag);
                $this->collectBlocks($child, $blocks, $dropped);

                continue;
            }

            $mark = $this->markFor($tag, $child, $dropped);

            if (! $mark && ! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->drop($dropped, $tag);
            }

            $this->collectInline($child, $mark ? [$mark] : [], $inline, $dropped);
        }

        $this->flush($inline, $blocks);
    }

    private function collectInline(DOMElement $element, array $marks, array &$inline, array &$dropped): void
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                $this->appendText($child->nodeValue, $marks, $inline);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $this->drop($dropped, $tag);

                continue;
            }

            if ($tag === 'br') {
                $inline[] = ['text' => "\n", 'marks' => []];

                continue;
            }

            $mark = $this->markFor($tag, $child, $dropped);
            $nested = $mark ? [...$marks, $mark] : $marks;

            if (! $mark && in_array($tag, self::BLOCK_TAGS, true)) {
                $this->drop($dropped, $tag);
            } elseif (! $mark && ! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->drop($dropped, $tag);
            }

            $this->collectInline($child, $nested, $inline, $dropped);
        }
    }

    private function markFor(string $tag, DOMElement $element, array &$dropped): ?array
    {
        return match ($tag) {
            'strong', 'b' => ['type' => 'bold'],
            'em', 'i' => ['type' => 'italic'],
            'u' => ['type' => 'underline'],
            's' => ['type' => 'strike'],
            'sup' => ['type' => 'superscript'],
            'sub' => ['type' => 'subscript'],
            'a' => $this->linkMark($element, $dropped),
            default => null,
        };
    }

    private function linkMark(DOMElement $element, array &$dropped): ?array
    {
        $href = trim($element->getAttribute('href'));

        if (! preg_match('#^https?://#i', $href) || preg_match('/[\x00-\x20\x7f]/', $href)) {
            $this->drop($dropped, 'a');

            return null;
        }

        return ['type' => 'link', 'attrs' => ['href' => $href]];
    }

    private function appendText(string $text, array $marks, array &$inline): void
    {
        $text = preg_replace('/[ \t\r\n\f]+/u', ' ', $text) ?? '';

        if ($text !== '') {
            $inline[] = ['text' => $text, 'marks' => $marks];
        }
    }

    private function flush(array &$inline, array &$blocks): void
    {
        if ($inline === []) {
            return;
        }

        $fragments = $inline;
        $inline = [];
        $text = '';
        $marks = [];
        $offset = 0;

        foreach ($fragments as $fragment) {
            $length = mb_strlen($fragment['text'], 'UTF-8');

            foreach ($fragment['marks'] as $mark) {
                $marks[] = [...$mark, 'start' => $offset, 'end' => $offset + $length];
            }

            $text .= $fragment['text'];
            $offset += $length;
        }

        $trimmed = trim($text);

        if ($trimmed === '') {
            return;
        }

        $leading = mb_strlen($text, 'UTF-8') - mb_strlen(ltrim($text), 'UTF-8');
        $limit = mb_strlen(rtrim($text), 'UTF-8');
        $adjusted = [];

        foreach ($marks as $mark) {
            $start = max($mark['start'], $leading);
            $end = min($mark['end'], $limit);

            if ($end <= $start) {
                continue;
            }

            $mark['start'] = $start - $leading;
            $mark['end'] = $end - $leading;
            $adjusted[] = $mark;
        }

        $blocks[] = [
            'id' => 'paste-'.(count($blocks) + 1),
            'type' => 'paragraph',
            'text' => $trimmed,
            'marks' => $adjusted,
        ];
    }

    private function drop(array &$dropped, string $tag): void
    {
        if (! in_array($tag, $dropped, true)) {
            $dropped[] = $tag;
        }
    }
}
