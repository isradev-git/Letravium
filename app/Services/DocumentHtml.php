<?php

namespace App\Services;

class DocumentHtml
{
    private const MARK_ORDER = [
        'link' => 0,
        'bold' => 1,
        'italic' => 2,
        'underline' => 3,
        'strike' => 4,
        'superscript' => 5,
        'subscript' => 6,
        'font_family' => 7,
        'font_size' => 8,
        'footnote_reference' => 9,
        'character_style' => 10,
    ];

    private array $noteNumbers = [];

    private array $characterStyles = [];

    public function withNotes(array $notes): static
    {
        $this->noteNumbers = [];

        foreach ($notes as $id => $number) {
            $id = (int) $id;
            $number = (int) $number;

            if ($id > 0 && $number > 0) {
                $this->noteNumbers[$id] = $number;
            }
        }

        return $this;
    }

    public function withStyles(array $styles): static
    {
        $this->characterStyles = [];

        foreach ($styles as $id => $definition) {
            if (is_array($definition)) {
                $this->characterStyles[(string) $id] = $definition;
            }
        }

        return $this;
    }

    private const MARK_TAGS = [
        'bold' => 'strong',
        'italic' => 'em',
        'underline' => 'u',
        'strike' => 's',
        'superscript' => 'sup',
        'subscript' => 'sub',
    ];

    public function render(array $document): string
    {
        $html = '';
        $orderedNumber = 0;

        foreach (($document['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? 'paragraph';

            if ($type === 'list_item' && ($block['list_type'] ?? null) === 'ordered') {
                $orderedNumber++;
            } else {
                $orderedNumber = 0;
            }

            $html .= $this->block($block, $orderedNumber);
        }

        return $html;
    }

    public function block(array $block, int $orderedNumber = 0): string
    {
        $type = $block['type'] ?? 'paragraph';
        $id = $this->escape((string) ($block['id'] ?? ''));

        if (in_array($type, ['scene_break', 'section_break', 'page_break', 'table', 'image'], true)) {
            $kind = in_array($type, ['table', 'image'], true) ? 'atom' : 'break';
            $label = match ($type) {
                'scene_break' => 'Separación de escena',
                'section_break' => 'Separación de sección',
                'page_break' => 'Salto de página',
                'table' => $this->table($block),
                'image' => $this->image($block),
            };

            return '<div class="editor-atom" contenteditable="false"'
                .' data-kind="'.$kind.'"'
                .' data-block-id="'.$id.'"'
                .' data-original="'.$this->escape(json_encode($block, JSON_UNESCAPED_UNICODE)).'">'
                .$label.'</div>';
        }

        $text = is_string($block['text'] ?? null) ? $block['text'] : '';
        $inner = $this->markedText($text, is_array($block['marks'] ?? null) ? $block['marks'] : []);
        $original = $this->escape(json_encode($block, JSON_UNESCAPED_UNICODE));
        $bookmark = isset($block['bookmark']) && is_string($block['bookmark']) && $block['bookmark'] !== ''
            ? ' data-bookmark="'.$this->escape($block['bookmark']).'"'
            : '';
        $format = $this->formatting($block);
        $style = $format['style'] !== '' ? ' style="'.$this->escape($format['style']).'"' : '';
        $attributes = ' class="editor-block" data-kind="text" data-block-id="'.$id.'"'
            .$format['data']
            .' data-original="'.$original.'"'.$bookmark.$style;

        if ($type === 'heading') {
            $level = $block['level'] ?? 2;
            $level = is_int($level) && $level >= 1 && $level <= 6 ? $level : 2;

            return "<h{$level}{$attributes}>{$inner}</h{$level}>";
        }

        if ($type === 'quote') {
            return "<blockquote{$attributes}>{$inner}</blockquote>";
        }

        if ($type === 'list_item') {
            $listType = in_array($block['list_type'] ?? null, ['bullet', 'ordered'], true)
                ? $block['list_type']
                : 'bullet';
            $depth = is_int($block['depth'] ?? null) ? max(0, min(5, $block['depth'])) : 0;
            $number = $listType === 'ordered' && $orderedNumber > 0
                ? ' data-number="'.$orderedNumber.'"'
                : '';

            return '<div'.$attributes.' data-list-type="'.$listType.'" data-depth="'.$depth.'"'.$number.'>'.$inner.'</div>';
        }

        return "<p{$attributes}>{$inner}</p>";
    }

    private function table(array $block): string
    {
        $rows = is_array($block['rows'] ?? null) ? $block['rows'] : [];
        $html = '<figure class="editor-figure editor-table"><table><tbody>';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $header = ($row['header'] ?? false) === true;
            $tag = $header ? 'th' : 'td';
            $scope = $header ? ' scope="col"' : '';
            $html .= '<tr>';

            foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $cell) {
                $text = is_array($cell) && is_string($cell['text'] ?? null) ? $cell['text'] : '';
                $html .= "<{$tag}{$scope}>".$this->escape($text)."</{$tag}>";
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        if (is_string($block['caption'] ?? null) && trim($block['caption']) !== '') {
            $html .= '<figcaption>'.$this->escape($block['caption']).'</figcaption>';
        }

        return $html.'</figure>';
    }

    private function image(array $block): string
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $assetId = $attrs['asset_id'] ?? null;

        if (! is_int($assetId) || $assetId < 1) {
            return '<span class="muted">Imagen no válida.</span>';
        }

        $alt = is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '';
        $alignment = in_array($attrs['alignment'] ?? null, ContentDocument::IMAGE_ALIGNMENTS, true)
            ? $attrs['alignment']
            : 'center';
        $html = '<figure class="editor-figure editor-image" data-alignment="'.$alignment.'">'
            .'<img src="/workspace/assets/'.$assetId.'/view" alt="'.$this->escape($alt).'"';

        foreach (['width', 'height'] as $key) {
            if (is_int($attrs[$key] ?? null) && $attrs[$key] > 0) {
                $html .= ' '.$key.'="'.$attrs[$key].'"';
            }
        }

        $html .= ' loading="lazy">';

        if (is_string($attrs['caption'] ?? null) && trim($attrs['caption']) !== '') {
            $html .= '<figcaption>'.$this->escape($attrs['caption']).'</figcaption>';
        }

        return $html.'</figure>';
    }

    private function formatting(array $block): array
    {
        $data = '';
        $style = [];

        $align = $block['align'] ?? null;

        if (in_array($align, ['left', 'center', 'right', 'justify'], true)) {
            $data .= ' data-align="'.$align.'"';
            $style[] = 'text-align: '.$align;
        }

        $family = $block['font_family'] ?? null;
        $familyCss = $this->familyCss($family);

        if ($familyCss !== null) {
            $data .= ' data-font-family="'.$this->escape((string) $family).'"';
            $style[] = 'font-family: '.$familyCss;
        }

        $ranges = [
            'font_size' => ['data-font-size', 'font-size', 'pt'],
            'indent_first' => ['data-indent-first', 'text-indent', 'em'],
            'indent_left' => ['data-indent-left', 'margin-left', 'em'],
            'line_height' => ['data-line-height', 'line-height', '%'],
            'spacing_before' => ['data-spacing-before', 'margin-top', 'pt'],
            'spacing_after' => ['data-spacing-after', 'margin-bottom', 'pt'],
        ];

        foreach ($ranges as $key => [$attribute, $property, $unit]) {
            $value = $block[$key] ?? null;

            if (is_int($value)) {
                $data .= " {$attribute}=\"{$value}\"";
                $style[] = "{$property}: {$value}{$unit}";
            }
        }

        return ['data' => $data, 'style' => implode('; ', $style)];
    }

    private function markedText(string $text, array $marks): string
    {
        $length = mb_strlen($text, 'UTF-8');
        $active = [];

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $type = $mark['type'] ?? null;

            if (! isset(self::MARK_ORDER[$type])) {
                continue;
            }

            $start = $mark['start'] ?? null;
            $end = $mark['end'] ?? null;

            if (! is_int($start) || ! is_int($end)) {
                continue;
            }

            $start = max(0, $start);
            $end = min($length, $end);

            if ($end <= $start) {
                continue;
            }

            $href = null;
            $attrs = [];

            if ($type === 'link') {
                $href = $mark['attrs']['href'] ?? null;

                if (! $this->safeHref($href)) {
                    continue;
                }
            }

            if ($type === 'font_family') {
                $family = $mark['attrs']['family'] ?? null;

                if ($this->familyCss($family) === null) {
                    continue;
                }

                $attrs = ['family' => $family];
            }

            if ($type === 'font_size') {
                $size = $mark['attrs']['size'] ?? null;

                if (! is_int($size) || $size < 8 || $size > 72) {
                    continue;
                }

                $attrs = ['size' => $size];
            }

            if ($type === 'footnote_reference') {
                $noteId = $mark['attrs']['note_id'] ?? null;

                if (! is_int($noteId) || $noteId < 1) {
                    continue;
                }

                $attrs = ['note_id' => $noteId];
            }

            if ($type === 'character_style') {
                $styleId = $mark['attrs']['id'] ?? $mark['attrs']['style_id'] ?? null;

                if (! is_string($styleId) || $styleId === '') {
                    continue;
                }

                $attrs = ['id' => $styleId];
            }

            $active[] = ['type' => $type, 'start' => $start, 'end' => $end, 'href' => $href, 'attrs' => $attrs];
        }

        if ($active === [] || $length === 0) {
            return $this->escape($text);
        }

        $points = [0, $length];

        foreach ($active as $mark) {
            $points[] = $mark['start'];
            $points[] = $mark['end'];
        }

        $points = array_values(array_unique($points));
        sort($points);

        $html = '';
        $buffer = '';
        $previousKey = null;
        $previousSet = [];

        for ($i = 0, $last = count($points) - 1; $i < $last; $i++) {
            $start = $points[$i];
            $end = $points[$i + 1];
            $set = array_values(array_filter(
                $active,
                fn (array $mark) => $mark['start'] <= $start && $mark['end'] >= $end,
            ));
            usort($set, fn (array $a, array $b) => self::MARK_ORDER[$a['type']] <=> self::MARK_ORDER[$b['type']]);

            $key = json_encode(array_map(fn (array $mark) => [$mark['type'], $mark['href'], $mark['attrs']], $set));
            $segment = mb_substr($text, $start, $end - $start, 'UTF-8');

            if ($key === $previousKey) {
                $buffer .= $segment;

                continue;
            }

            $html .= $this->wrap($buffer, $previousSet);
            $buffer = $segment;
            $previousKey = $key;
            $previousSet = $set;
        }

        return $html.$this->wrap($buffer, $previousSet);
    }

    private function wrap(string $text, array $marks): string
    {
        $html = $this->escape($text);

        foreach (array_reverse($marks) as $mark) {
            if ($mark['type'] === 'link') {
                $html = '<a href="'.$this->escape($mark['href']).'" rel="noopener noreferrer">'.$html.'</a>';

                continue;
            }

            if ($mark['type'] === 'font_family') {
                $family = $mark['attrs']['family'];
                $css = $this->familyCss($family) ?? "'Source Serif 4', Georgia, serif";
                $html = '<span data-mark="font_family" data-value="'.$this->escape($family).'"'
                    .' style="font-family: '.$this->escape($css).'">'.$html.'</span>';

                continue;
            }

            if ($mark['type'] === 'font_size') {
                $size = $mark['attrs']['size'];
                $html = '<span data-mark="font_size" data-value="'.$size.'"'
                    .' style="font-size: '.$size.'pt">'.$html.'</span>';

                continue;
            }

            if ($mark['type'] === 'footnote_reference') {
                $noteId = $mark['attrs']['note_id'];
                $number = $this->noteNumbers[$noteId] ?? null;
                $label = $number !== null ? (string) $number : '?';
                $html = '<span class="editor-note-anchor" data-mark="footnote_reference"'
                    .' data-note-id="'.$noteId.'" data-note-number="'.$label.'"'
                    .' title="Nota '.$label.'">'.$html.'</span>';

                continue;
            }

            if ($mark['type'] === 'character_style') {
                $styleId = $mark['attrs']['id'];
                $definition = $this->characterStyles[$styleId] ?? [];
                $css = $this->characterStyleCss($definition);
                $style = $css !== '' ? ' style="'.$this->escape($css).'"' : '';
                $html = '<span class="editor-character-style" data-mark="character_style"'
                    .' data-style-id="'.$this->escape($styleId).'"'.$style.'>'.$html.'</span>';

                continue;
            }

            $tag = self::MARK_TAGS[$mark['type']];
            $html = "<{$tag}>{$html}</{$tag}>";
        }

        return $html;
    }

    private function characterStyleCss(array $definition): string
    {
        $css = [];

        if (($definition['bold'] ?? false) === true) {
            $css[] = 'font-weight: 700';
        }

        if (($definition['italic'] ?? false) === true) {
            $css[] = 'font-style: italic';
        }

        $decorations = [];

        if (($definition['underline'] ?? false) === true) {
            $decorations[] = 'underline';
        }

        if (($definition['strike'] ?? false) === true) {
            $decorations[] = 'line-through';
        }

        if ($decorations !== []) {
            $css[] = 'text-decoration: '.implode(' ', $decorations);
        }

        if (($definition['small_caps'] ?? false) === true) {
            $css[] = 'font-variant: small-caps';
        }

        $size = $definition['size'] ?? null;

        if (is_int($size) && $size >= 8 && $size <= 72) {
            $css[] = 'font-size: '.$size.'pt';
        }

        $color = $definition['color'] ?? null;

        if (is_string($color) && preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color)) {
            $css[] = 'color: '.$color;
        }

        $fontId = $definition['font_id'] ?? null;

        if (is_int($fontId) && $fontId > 0) {
            $css[] = "font-family: 'AppFont{$fontId}', serif";
        } else {
            $familyCss = $this->familyCss($definition['font_family'] ?? null);

            if ($familyCss !== null) {
                $css[] = 'font-family: '.$familyCss;
            }
        }

        return implode('; ', $css);
    }

    private function familyCss(mixed $family): ?string
    {
        if ($family === 'serif') {
            return "'Source Serif 4', Georgia, serif";
        }

        if ($family === 'sans') {
            return 'var(--font-sans)';
        }

        if (is_string($family) && preg_match('/^font:([1-9]\d*)$/', $family, $match) === 1) {
            return "'AppFont{$match[1]}', serif";
        }

        return null;
    }

    private function safeHref(mixed $href): bool
    {
        return is_string($href)
            && preg_match('#^https?://#i', $href) === 1
            && preg_match('/[\x00-\x20\x7f]/', $href) !== 1;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
