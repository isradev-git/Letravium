<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Novel;
use Illuminate\Support\Collection;

class ContentSearch
{
    private const MAX_RESULTS = 100;

    private const FOLD = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'Ý' => 'Y',
    ];

    public static function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'type' => ['nullable', 'in:chapter,scene,card'],
            'area' => ['nullable', 'in:'.implode(',', Card::AREAS)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'mode' => ['nullable', 'in:accent-insensitive,exact'],
            'case' => ['nullable', 'in:insensitive,sensitive'],
        ];
    }

    /**
     * @param  Collection<int, Novel>  $novels
     * @param  array<int, int>|null  $chapterIds  null searches every chapter in the novels
     * @return array{query: string, results: array, count: int, truncated: bool}
     */
    public function search(Collection $novels, array $data, ?array $chapterIds = null, bool $includeCards = true): array
    {
        $accentInsensitive = ($data['mode'] ?? 'accent-insensitive') !== 'exact';
        $caseInsensitive = ($data['case'] ?? 'insensitive') !== 'sensitive';
        $needle = $this->fold(trim((string) $data['q']), $accentInsensitive, $caseInsensitive);
        $type = $data['type'] ?? null;
        $tags = $data['tags'] ?? [];
        $results = [];
        $truncated = false;

        $push = function (array $result) use (&$results, &$truncated): void {
            if (count($results) >= self::MAX_RESULTS) {
                $truncated = true;

                return;
            }

            $results[] = $result;
        };

        if ($needle !== '') {
            foreach ($novels as $novel) {
                $query = $novel->chapters()->with([
                    'alternatives' => fn ($query) => $query->where('is_active', true)->with('scenes'),
                ]);

                if ($chapterIds !== null) {
                    $query->whereIn('id', $chapterIds);
                }

                foreach ($query->get() as $chapter) {
                    if (($type === null || $type === 'chapter')
                        && $this->matches((string) $chapter->title, $needle, $accentInsensitive, $caseInsensitive)) {
                        $push([
                            'type' => 'chapter',
                            'id' => $chapter->id,
                            'novel_id' => $novel->id,
                            'title' => $chapter->title,
                            'excerpt' => $this->excerpt((string) $chapter->title, $needle, $accentInsensitive, $caseInsensitive),
                            'position' => (int) $chapter->position,
                        ]);
                    }

                    if ($type !== null && $type !== 'scene') {
                        continue;
                    }

                    $active = $chapter->alternatives->firstWhere('is_active', true);

                    if (! $active) {
                        continue;
                    }

                    foreach ($active->scenes as $scene) {
                        foreach (($scene->content['blocks'] ?? []) as $block) {
                            $text = (string) ($block['text'] ?? '');

                            if ($text === '' || ! $this->matches($text, $needle, $accentInsensitive, $caseInsensitive)) {
                                continue;
                            }

                            $push([
                                'type' => 'scene',
                                'id' => $scene->id,
                                'novel_id' => $novel->id,
                                'scene_id' => $scene->id,
                                'chapter_id' => $chapter->id,
                                'block_id' => $block['id'] ?? null,
                                'title' => $chapter->title,
                                'excerpt' => $this->excerpt($text, $needle, $accentInsensitive, $caseInsensitive),
                                'position' => (int) $scene->position,
                            ]);
                        }
                    }
                }
            }

            if ($includeCards && ($type === null || $type === 'card')) {
                $cards = Card::whereIn('universe_id', $novels->pluck('universe_id')->unique()->all());

                if (! empty($data['area'])) {
                    $cards->where('area', $data['area']);
                }

                foreach ($cards->orderBy('area')->orderBy('name')->get() as $card) {
                    if ($tags !== [] && ! $this->hasTags($card, $tags)) {
                        continue;
                    }

                    $haystack = $card->name.' '.json_encode($card->fields ?? []).' '.json_encode($card->text ?? []);

                    if (! $this->matches($haystack, $needle, $accentInsensitive, $caseInsensitive)) {
                        continue;
                    }

                    $push([
                        'type' => 'card',
                        'id' => $card->id,
                        'title' => $card->name,
                        'area' => $card->area,
                        'excerpt' => $this->excerpt((string) $card->name, $needle, $accentInsensitive, $caseInsensitive),
                    ]);
                }
            }
        }

        return [
            'query' => $data['q'],
            'results' => $results,
            'count' => count($results),
            'truncated' => $truncated,
        ];
    }

    private function hasTags(Card $card, array $tags): bool
    {
        $cardTags = $card->fields['tags'] ?? [];

        if (! is_array($cardTags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (! in_array($tag, $cardTags, true)) {
                return false;
            }
        }

        return true;
    }

    private function matches(string $haystack, string $needle, bool $accentInsensitive, bool $caseInsensitive): bool
    {
        return mb_strpos($this->fold($haystack, $accentInsensitive, $caseInsensitive), $needle) !== false;
    }

    private function excerpt(string $text, string $needle, bool $accentInsensitive, bool $caseInsensitive): string
    {
        $position = mb_strpos($this->fold($text, $accentInsensitive, $caseInsensitive), $needle);

        if ($position === false) {
            return mb_substr($text, 0, 90);
        }

        $start = max(0, $position - 30);
        $snippet = mb_substr($text, $start, 90);

        return ($start > 0 ? '…' : '').$snippet.(mb_strlen($text) > $start + 90 ? '…' : '');
    }

    private function fold(string $text, bool $accentInsensitive, bool $caseInsensitive): string
    {
        if ($caseInsensitive) {
            $text = mb_strtolower($text, 'UTF-8');
        }

        if (! $accentInsensitive) {
            return $text;
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(fn (string $character) => self::FOLD[$character] ?? $character, $characters));
    }
}
