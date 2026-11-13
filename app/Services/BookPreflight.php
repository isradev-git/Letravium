<?php

namespace App\Services;

use App\Models\BookMark;
use App\Models\BookProfile;
use App\Models\Chapter;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Style;
use Illuminate\Support\Facades\Storage;

class BookPreflight
{
    public function run(Novel $novel): array
    {
        $errors = [];
        $warnings = [];

        $profile = $novel->hasMany(BookProfile::class)->latest('id')->first();

        if (! $profile) {
            $warnings[] = $this->issue('profile.missing', 'No hay perfil editorial; se usarán medidas por defecto.', 'warning');
        }

        $styles = Style::where('novel_id', $novel->id)->get();
        $fontIds = $this->fontIds($profile, $styles);

        foreach ($fontIds as $fontId) {
            $font = Font::whereKey($fontId)->where('owner_id', $novel->owner_id)->first();

            if (! $font) {
                $errors[] = $this->issue('font.missing', "La fuente {$fontId} ya no existe.", 'error', ['font_id' => $fontId]);

                continue;
            }

            if (! Storage::disk('local')->exists($font->path)) {
                $errors[] = $this->issue('font.file_missing', "Falta el archivo de la fuente {$font->family}.", 'error', ['font_id' => $fontId]);
            }

            if ($font->license === null || trim((string) $font->license) === '') {
                $warnings[] = $this->issue('font.restricted', "La licencia de {$font->family} no está declarada.", 'warning', ['font_id' => $fontId]);
            }

            if (($font->metadata['format'] ?? null) === 'otf') {
                $warnings[] = $this->issue('font.otf_unverified', "La inclusión OTF de {$font->family} no está verificada.", 'warning', ['font_id' => $fontId]);
            }
        }

        $chapters = $novel->chapters()->orderBy('position')->get();
        $activeSceneIds = [];
        $bookText = '';

        foreach ($chapters as $chapter) {
            $active = $chapter->alternatives()->where('is_active', true)->first();

            if (! $active) {
                $warnings[] = $this->issue('chapter.empty', $this->chapterName($chapter).' no tiene una alternativa activa.', 'warning', ['chapter_id' => $chapter->id]);

                continue;
            }

            $text = '';

            foreach ($active->scenes()->orderBy('position')->get() as $scene) {
                $activeSceneIds[] = $scene->id;

                foreach (($scene->content['blocks'] ?? []) as $block) {
                    $text .= (string) ($block['text'] ?? '');
                }
            }

            $bookText .= $text;

            if (trim($text) === '') {
                $warnings[] = $this->issue('chapter.empty', $this->chapterName($chapter).' no tiene prosa.', 'warning', ['chapter_id' => $chapter->id]);
            }
        }

        $inspector = app(FontInspector::class);
        $uniqueText = implode('', array_unique(preg_split('//u', $bookText, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        foreach ($fontIds as $fontId) {
            $font = Font::whereKey($fontId)->where('owner_id', $novel->owner_id)->first();

            if (! $font || ! Storage::disk('local')->exists($font->path)) {
                continue;
            }

            $info = $inspector->inspect(Storage::disk('local')->path($font->path));

            if ($info === null) {
                continue;
            }

            $fsType = $info['fs_type'];

            if ($fsType !== null && (($fsType & 0x0002) !== 0 || ($fsType & 0x0200) !== 0)) {
                $errors[] = $this->issue('font.embedding_restricted', "La fuente {$font->family} no permite la inclusión requerida.", 'error', ['font_id' => $font->id]);
            }

            $missing = $inspector->missing($uniqueText, $info['coverage']);

            if ($missing !== []) {
                $sample = implode(' ', array_slice($missing, 0, 20));
                $errors[] = $this->issue('font.coverage', "La fuente {$font->family} no contiene: {$sample}.", 'error', [
                    'font_id' => $font->id,
                    'missing' => array_slice($missing, 0, 50),
                ]);
            }
        }

        $assetIds = $this->assetIds($novel, $profile?->cover);

        foreach ($assetIds as $assetId) {
            $asset = MediaAsset::whereKey($assetId)->where('owner_id', $novel->owner_id)->first();

            if (! $asset) {
                $errors[] = $this->issue('resource.broken_reference', "El recurso {$assetId} no existe.", 'error', ['asset_id' => $assetId]);

                continue;
            }

            if (! Storage::disk('local')->exists($asset->path)) {
                $errors[] = $this->issue('resource.missing_file', "Falta el archivo de {$asset->original_name}.", 'error', ['asset_id' => $assetId]);
            }

            if ($asset->kind === 'image' && ($warning = $this->lowResolution($asset, $profile)) !== null) {
                $warnings[] = $this->issue('image.low_resolution', $warning, 'warning', ['asset_id' => $assetId]);
            }
        }

        foreach (Footnote::where('novel_id', $novel->id)->orderBy('kind')->orderBy('number')->get() as $note) {
            if (trim((string) $note->text) === '') {
                $errors[] = $this->issue('note.incomplete', "La nota {$note->number} está vacía.", 'error', ['footnote_id' => $note->id]);
            }

            if ($note->scene_id === null) {
                $warnings[] = $this->issue('note.unanchored', "La nota {$note->number} no está anclada a una escena.", 'warning', ['footnote_id' => $note->id]);
            } elseif (! in_array($note->scene_id, $activeSceneIds, true)) {
                $errors[] = $this->issue('note.broken_reference', "La nota {$note->number} apunta a una escena no exportable.", 'error', ['footnote_id' => $note->id]);
            }
        }

        foreach (BookMark::where('novel_id', $novel->id)->get() as $mark) {
            if (in_array($mark->type, ['index', 'glossary'], true) && trim((string) $mark->label) === '') {
                $errors[] = $this->issue('index.incomplete', "Hay una marca de {$mark->type} sin término.", 'error', ['mark_id' => $mark->id]);
            }

            if (in_array($mark->type, ['figure', 'table'], true)
                && trim((string) ($mark->caption ?? $mark->label ?? '')) === '') {
                $warnings[] = $this->issue('caption.missing', "Hay una {$mark->type} sin pie de página.", 'warning', ['mark_id' => $mark->id]);
            }
        }

        return [
            'can_export' => $errors === [],
            'blocking' => $errors,
            'warnings' => $warnings,
            'counts' => ['blocking' => count($errors), 'warnings' => count($warnings)],
        ];
    }

    private function fontIds(?BookProfile $profile, $styles): array
    {
        $ids = [];

        $collector = function ($key, $value) use (&$ids) {
            if (is_string($key) && str_contains(strtolower($key), 'font')) {
                $this->addId($ids, $value);
            }
        };

        if ($profile) {
            $fonts = $profile->fonts ?? [];
            if (is_array($fonts) && array_is_list($fonts)) {
                foreach ($fonts as $value) {
                    $this->addId($ids, $value);
                }
            }
            $this->walk($fonts, $collector);
        }

        $this->walk($styles->map->definition->all(), $collector);

        return array_values(array_unique($ids));
    }

    private function assetIds(Novel $novel, ?array $cover): array
    {
        $ids = [];

        $collector = function ($key, $value) use (&$ids) {
            if (is_string($key)
                && (str_contains(strtolower($key), 'asset')
                    || str_contains(strtolower($key), 'image')
                    || str_contains(strtolower($key), 'media'))) {
                $this->addId($ids, $value);
            }
        };

        foreach ($novel->chapters()->orderBy('position')->get() as $chapter) {
            $active = $chapter->alternatives()->where('is_active', true)->first();
            if (! $active) {
                continue;
            }
            foreach ($active->scenes()->orderBy('position')->get() as $scene) {
                $this->walk($scene->content ?? [], $collector);
            }
        }

        $this->walk($cover ?? [], $collector);

        return array_values(array_unique($ids));
    }

    private function lowResolution(MediaAsset $asset, ?BookProfile $profile): ?string
    {
        $width = (int) ($asset->metadata['width'] ?? 0);
        $height = (int) ($asset->metadata['height'] ?? 0);

        if ($width < 1 || $height < 1) {
            return "{$asset->original_name} no declara dimensiones.";
        }

        [$pageWidth, $pageHeight] = $this->pageMillimetres($profile?->page_size);
        $margins = $profile?->margins ?? [];
        $horizontal = $this->millimetres($margins['left'] ?? 16) + $this->millimetres($margins['right'] ?? 16);
        $contentWidth = max(1.0, $pageWidth - $horizontal);
        $required = (int) ceil($contentWidth / 25.4 * 300);

        if (max($width, $height) < $required) {
            return "{$asset->original_name} tiene {$width}×{$height} px; se recomiendan al menos {$required} px de ancho.";
        }

        return null;
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

    private function chapterName(Chapter $chapter): string
    {
        return $chapter->title ?: 'Capítulo '.($chapter->position + 1);
    }

    private function addId(array &$ids, mixed $value): void
    {
        if (is_int($value) && $value > 0) {
            $ids[] = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $ids[] = (int) $value;
        } elseif (is_array($value) && isset($value['id']) && is_numeric($value['id'])) {
            $ids[] = (int) $value['id'];
        }
    }

    private function walk(mixed $value, callable $visit): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $visit($key, $child);
                $this->walk($child, $visit);
            }
        }
    }

    private function issue(string $code, string $message, string $severity, array $context = []): array
    {
        return ['code' => $code, 'message' => $message, 'severity' => $severity] + $context;
    }
}
