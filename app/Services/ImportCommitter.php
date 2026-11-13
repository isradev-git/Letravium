<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ImportCommitter
{
    public function commit(User $user, array $data): Novel
    {
        return DB::transaction(function () use ($user, $data): Novel {
            if ($data['mode'] === 'new_novel') {
                $universe = Universe::whereKey($data['universe_id'])
                    ->where('owner_id', $user->id)
                    ->firstOrFail();

                $novel = Novel::create([
                    'universe_id' => $universe->id,
                    'owner_id' => $user->id,
                    'title' => $data['title'],
                ]);

                $position = 0;
            } else {
                $novel = Novel::whereKey($data['novel_id'])
                    ->where('owner_id', $user->id)
                    ->firstOrFail();

                $position = (int) $novel->chapters()->max('position') + 1;
            }

            foreach ($data['chapters'] as $index => $chapterData) {
                $chapter = Chapter::create([
                    'novel_id' => $novel->id,
                    'title' => $chapterData['title'] ?? null,
                    'position' => $position + $index,
                ]);

                $alternative = ChapterAlternative::create([
                    'chapter_id' => $chapter->id,
                    'alias' => 'Principal',
                    'is_active' => true,
                ]);

                Scene::create([
                    'chapter_alternative_id' => $alternative->id,
                    'title' => $chapterData['title'] ?? null,
                    'position' => 0,
                    'content' => $this->content($chapterData['text']),
                ]);
            }

            return $novel;
        });
    }

    private function content(string $text): array
    {
        $blocks = [];
        $index = 1;

        foreach (explode("\n", ImportText::normalize($text)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $blocks[] = ['id' => 'b'.$index++, 'type' => 'paragraph', 'text' => $line];
        }

        if ($blocks === []) {
            $blocks[] = ['id' => 'b1', 'type' => 'paragraph', 'text' => ''];
        }

        return ['schema_version' => 1, 'blocks' => $blocks];
    }
}
