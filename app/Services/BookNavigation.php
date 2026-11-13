<?php

namespace App\Services;

use App\Models\BookMark;
use App\Models\Novel;
use Illuminate\Support\Str;

class BookNavigation
{
    public function build(Novel $novel): array
    {
        $toc = [];

        foreach ($novel->chapters()->orderBy('position')->get() as $chapter) {
            $active = $chapter->alternatives()->where('is_active', true)->first();
            $headings = [];

            if ($active) {
                foreach ($active->scenes()->orderBy('position')->get() as $scene) {
                    foreach (($scene->content['blocks'] ?? []) as $block) {
                        if (($block['type'] ?? null) === 'heading') {
                            $headings[] = [
                                'title' => (string) ($block['text'] ?? ''),
                                'level' => (int) ($block['level'] ?? 2),
                                'target' => "chapter:{$chapter->id}:scene:{$scene->id}:block:".($block['id'] ?? ''),
                            ];
                        }
                    }
                }
            }

            $toc[] = [
                'title' => $chapter->title ?: 'Capítulo '.($chapter->position + 1),
                'level' => 1,
                'target' => "chapter:{$chapter->id}",
                'children' => $headings,
            ];
        }

        $marks = $novel->hasMany(BookMark::class)->get();

        $markList = fn (string $type) => $marks->where('type', $type)
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (BookMark $mark) => [
                'id' => $mark->id,
                'label' => $mark->label,
                'caption' => $mark->caption,
                'target_type' => $mark->target_type,
                'target_id' => $mark->target_id,
                'data' => $mark->data,
            ])->all();

        $index = $markList('index');
        $index = array_map(function (array $entry) {
            $key = (string) ($entry['label'] ?? '');
            $ascii = Str::ascii($key);
            $entry['letter'] = $ascii !== '' ? mb_strtoupper(mb_substr($ascii, 0, 1)) : '#';

            return $entry;
        }, $index);
        usort($index, fn ($a, $b) => strcmp(
            Str::ascii(($a['label'] ?? '').' '.$a['id']),
            Str::ascii(($b['label'] ?? '').' '.$b['id']),
        ));

        return [
            'toc' => $toc,
            'figures' => $markList('figure'),
            'tables' => $markList('table'),
            'index' => $index,
            'glossary' => $markList('glossary'),
        ];
    }
}
