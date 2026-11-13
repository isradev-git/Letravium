<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\EditLease;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReplacementService
{
    public const MAX_MATCHES = 1000;

    public function __construct(
        private HistoryService $history,
        private ContentDocument $documents,
    ) {}

    public function preview(Novel $novel, User $user, array $data): array
    {
        $chapters = $this->chapters($novel, $user, $data['chapter_ids']);
        $matches = [];
        $truncated = false;
        $chapterRevisions = [];
        $sceneRevisions = [];
        $sceneHashes = [];

        foreach ($chapters as $chapter) {
            $chapterRevisions[(int) $chapter->id] = (int) $chapter->revision;
            $active = $chapter->alternatives()->where('is_active', true)->first();

            if (! $active) {
                continue;
            }

            foreach ($active->scenes()->orderBy('position')->orderBy('id')->get() as $scene) {
                $content = is_array($scene->content) ? $scene->content : [];
                $sceneRevisions[(int) $scene->id] = (int) $scene->revision;
                $sceneHashes[(int) $scene->id] = hash('sha256', (string) json_encode($content));

                foreach (($content['blocks'] ?? []) as $block) {
                    if (! is_array($block) || ! in_array($block['type'] ?? null, ContentDocument::TEXT_TYPES, true)) {
                        continue;
                    }

                    $text = is_string($block['text'] ?? null) ? $block['text'] : '';

                    foreach ($this->findMatches($text, $data) as [$start, $end]) {
                        $matches[] = [
                            'chapter_id' => (int) $chapter->id,
                            'scene_id' => (int) $scene->id,
                            'block_id' => (string) ($block['id'] ?? ''),
                            'start' => $start,
                            'end' => $end,
                            'excerpt' => $this->excerpt($text, $start, $end),
                        ];

                        if (count($matches) >= self::MAX_MATCHES) {
                            $truncated = true;
                            break 4;
                        }
                    }
                }
            }
        }

        $preview = [
            'chapter_ids' => array_map('intval', $chapters->pluck('id')->all()),
            'chapter_revisions' => $chapterRevisions,
            'scene_revisions' => $sceneRevisions,
            'scene_hashes' => $sceneHashes,
            'matches' => $matches,
            'count' => count($matches),
            'truncated' => $truncated,
        ];
        $preview['preview_hash'] = $this->fingerprint($data, $preview);

        return $preview;
    }

    public function apply(Novel $novel, User $user, array $data): array
    {
        $preview = $this->preview($novel, $user, $data);

        if ($preview['truncated']) {
            abort(422, 'La búsqueda devuelve demasiados resultados; reduce el alcance.');
        }

        if (! hash_equals($preview['preview_hash'], (string) ($data['preview_hash'] ?? ''))) {
            abort(409, 'La vista previa está desactualizada.');
        }

        $this->assertEdit($user, $preview['chapter_ids']);
        $this->assertLeases($novel, $user, $preview['chapter_ids']);

        if ($preview['count'] === 0) {
            return ['replaced' => 0, 'scenes' => [], 'chapters' => []];
        }

        $matchesByScene = collect($preview['matches'])->groupBy('scene_id');

        return DB::transaction(function () use ($preview, $matchesByScene, $user, $data) {
            $chapterIds = $preview['chapter_ids'];
            $lockedChapters = Chapter::whereIn('id', $chapterIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($chapterIds as $chapterId) {
                if (! isset($lockedChapters[$chapterId])
                    || (int) $lockedChapters[$chapterId]->revision !== (int) $preview['chapter_revisions'][$chapterId]) {
                    abort(409, 'La vista previa está desactualizada.');
                }
            }

            $updatedScenes = [];
            $affectedChapters = [];

            foreach ($matchesByScene->sortKeys() as $sceneId => $matches) {
                $scene = Scene::whereKey($sceneId)->lockForUpdate()->firstOrFail();
                $chapterId = (int) ChapterAlternative::whereKey($scene->chapter_alternative_id)->value('chapter_id');

                if ((int) $scene->revision !== (int) $preview['scene_revisions'][$sceneId]
                    || hash('sha256', (string) json_encode($scene->content ?? [])) !== $preview['scene_hashes'][$sceneId]) {
                    abort(409, 'La vista previa está desactualizada.');
                }

                $content = is_array($scene->content) ? $scene->content : [
                    'schema_version' => ContentDocument::SCHEMA_VERSION,
                    'blocks' => [],
                ];
                $byBlock = collect($matches)->groupBy('block_id');

                foreach ($content['blocks'] as $index => $block) {
                    if (! is_array($block) || ! $byBlock->has((string) ($block['id'] ?? ''))) {
                        continue;
                    }

                    $content['blocks'][$index] = $this->replaceBlock(
                        $block,
                        $byBlock->get((string) $block['id'])->all(),
                        (string) $data['replacement'],
                    );
                }

                $content = $this->documents->validate($content);

                $this->history->record((int) $scene->id, (int) $scene->revision, $scene->content ?? [], $user->id, 'replace');
                $scene->forceFill([
                    'content' => $content,
                    'revision' => (int) $scene->revision + 1,
                ])->save();

                $updatedScenes[] = (int) $scene->id;
                $affectedChapters[$chapterId] = true;
            }

            foreach (array_keys($affectedChapters) as $chapterId) {
                $chapter = $lockedChapters[$chapterId];
                $chapter->forceFill(['revision' => (int) $chapter->revision + 1])->save();
            }

            return [
                'replaced' => count($preview['matches']),
                'scenes' => $updatedScenes,
                'chapters' => array_keys($affectedChapters),
            ];
        });
    }

    private function chapters(Novel $novel, User $user, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $chapters = $novel->chapters()->whereIn('id', $ids)->orderBy('position')->get();

        abort_unless($chapters->count() === count($ids), 404);

        foreach ($chapters as $chapter) {
            abort_unless(CreativeAccess::allows($user, 'chapter', (int) $chapter->id, 'read'), 404);
        }

        return $chapters;
    }

    private function assertEdit(User $user, array $chapterIds): void
    {
        foreach ($chapterIds as $chapterId) {
            if (CreativeAccess::allows($user, 'chapter', (int) $chapterId, 'edit')) {
                continue;
            }

            abort_if(CreativeAccess::allows($user, 'chapter', (int) $chapterId, 'read'), 403);

            abort(404);
        }
    }

    private function assertLeases(Novel $novel, User $user, array $chapterIds): void
    {
        $isOwner = (int) $novel->owner_id === (int) $user->id;

        foreach ($chapterIds as $chapterId) {
            $lease = EditLease::where('resource_type', 'chapter')
                ->where('resource_id', $chapterId)
                ->where('expires_at', '>', now())
                ->first();

            if ($lease && (int) $lease->user_id !== (int) $user->id) {
                abort(423, 'Reserva activa de otro editor.');
            }

            if (! $isOwner && (! $lease || (int) $lease->user_id !== (int) $user->id)) {
                abort(423, 'lease_required');
            }
        }
    }

    private function findMatches(string $text, array $data): array
    {
        $search = (string) $data['search'];

        if ($text === '' || $search === '') {
            return [];
        }

        $quoted = preg_quote($search, '/');
        $body = ($data['whole_word'] ?? true)
            ? '(?<![\p{L}\p{N}_])'.$quoted.'(?![\p{L}\p{N}_])'
            : $quoted;
        $modifiers = 'u'.(($data['case_sensitive'] ?? false) ? '' : 'i');

        preg_match_all('/'.$body.'/'.$modifiers, $text, $found, PREG_OFFSET_CAPTURE);
        $matches = [];

        foreach ($found[0] as [$matched, $byteOffset]) {
            $start = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
            $end = $start + mb_strlen($matched, 'UTF-8');
            $matches[] = [$start, $end];
        }

        return $matches;
    }

    private function replaceBlock(array $block, array $matches, string $replacement): array
    {
        $text = is_string($block['text'] ?? null) ? $block['text'] : '';
        $marks = is_array($block['marks'] ?? null) ? $block['marks'] : [];
        $length = mb_strlen($replacement, 'UTF-8');

        usort($matches, fn (array $a, array $b): int => (int) $b['start'] <=> (int) $a['start']);

        foreach ($matches as $match) {
            $start = (int) $match['start'];
            $end = (int) $match['end'];
            $delta = $length - ($end - $start);

            foreach ($marks as &$mark) {
                $markStart = $mark['start'] ?? null;
                $markEnd = $mark['end'] ?? null;

                if (! is_int($markStart) || ! is_int($markEnd) || $markEnd <= $start) {
                    continue;
                }

                if ($markStart >= $end) {
                    $mark['start'] = $markStart + $delta;
                    $mark['end'] = $markEnd + $delta;

                    continue;
                }

                $mark['start'] = $markStart < $start ? $markStart : $start;
                $mark['end'] = $markEnd > $end ? $markEnd + $delta : $start + $length;
            }
            unset($mark);

            $text = mb_substr($text, 0, $start).$replacement.mb_substr($text, $end);
        }

        $block['text'] = $text;
        $block['marks'] = array_values(array_filter(
            $marks,
            fn (array $mark): bool => is_int($mark['start'] ?? null)
                && is_int($mark['end'] ?? null)
                && $mark['end'] > $mark['start'],
        ));

        return $block;
    }

    private function excerpt(string $text, int $start, int $end): string
    {
        $from = max(0, $start - 30);
        $snippet = mb_substr($text, $from, ($end - $start) + 60, 'UTF-8');

        return ($from > 0 ? '…' : '').$snippet.(mb_strlen($text, 'UTF-8') > $from + mb_strlen($snippet, 'UTF-8') ? '…' : '');
    }

    private function fingerprint(array $data, array $preview): string
    {
        $payload = [
            'search' => (string) $data['search'],
            'replacement' => (string) $data['replacement'],
            'whole_word' => (bool) ($data['whole_word'] ?? true),
            'case_sensitive' => (bool) ($data['case_sensitive'] ?? false),
            'chapter_ids' => $preview['chapter_ids'],
            'chapter_revisions' => $preview['chapter_revisions'],
            'scene_revisions' => $preview['scene_revisions'],
            'scene_hashes' => $preview['scene_hashes'],
            'matches' => $preview['matches'],
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
