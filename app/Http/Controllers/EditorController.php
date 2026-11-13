<?php

namespace App\Http\Controllers;

use App\Models\Font;
use App\Models\Footnote;
use App\Models\Novel;
use App\Services\ContentDocument;
use App\Services\CreativeAccess;
use App\Services\DocumentHtml;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;

class EditorController extends Controller
{
    public function showNovel(Request $request, Novel $novel, DocumentHtml $documentHtml)
    {
        $novel->loadMissing('universe');
        $user = $request->user();
        $isOwner = (int) $novel->owner_id === (int) $user->id;
        $accessibleIds = $isOwner ? null : CreativeAccess::accessibleChapterIds($user, $novel);

        abort_if(! $isOwner && $accessibleIds === [], 404);

        $chapterListQuery = $novel->chapters()->orderBy('position');
        $chapterQuery = $novel->chapters()
            ->with(['alternatives' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['scenes' => fn ($scenes) => $scenes
                    ->orderBy('position')
                    ->orderBy('id')])]);

        if ($accessibleIds !== null) {
            $chapterListQuery->whereIn('id', $accessibleIds);
            $chapterQuery->whereIn('id', $accessibleIds);
        }

        $chapterList = $chapterListQuery->get(['id', 'title', 'position']);
        $editableChapterIds = $chapterList
            ->filter(fn ($chapter) => CreativeAccess::allows($user, 'chapter', (int) $chapter->id, 'edit'))
            ->map(fn ($chapter) => (int) $chapter->id)
            ->all();
        $mode = $request->query('view') === 'chapter' ? 'chapter' : 'manuscript';
        $selectedChapterId = null;
        $hasMore = false;
        $nextCursor = null;
        $query = $chapterQuery;

        if ($mode === 'chapter') {
            $selectedChapterId = (int) $request->query('chapter', 0);
            $chapter = $query->whereKey($selectedChapterId)->first();
            abort_unless($chapter, 404);
            $chapters = collect([$chapter]);
        } else {
            $limit = 10;
            $cursor = max(0, (int) $request->query('cursor', 0));

            if ($cursor > 0) {
                $query->where('position', '>=', $cursor);
            }

            $chapters = $query->limit($limit + 1)->get();
            $hasMore = $chapters->count() > $limit;
            $chapters = $chapters->take($limit)->values();
            $nextCursor = $hasMore && $chapters->last()
                ? (int) $chapters->last()->position + 1
                : null;
        }

        $sceneIds = $chapters
            ->flatMap(fn ($chapter) => $chapter->alternatives->flatMap(
                fn ($alternative) => $alternative->scenes->pluck('id'),
            ))
            ->map(fn ($id) => (int) $id)
            ->all();
        $notes = Footnote::where('novel_id', $novel->id)
            ->when(! $isOwner, fn ($query) => $query->whereIn('scene_id', $sceneIds))
            ->get();
        $documentHtml->withNotes($notes->pluck('number', 'id')->all());
        $characterStyles = $novel->styles()
            ->where('kind', 'character')
            ->orderBy('name')
            ->get(['id', 'name', 'definition']);
        $documentHtml->withStyles(
            $characterStyles->mapWithKeys(fn ($style) => [(string) $style->id => $style->definition ?? []])->all(),
        );

        $fontIds = [];

        foreach ($novel->styles()->get(['definition']) as $style) {
            $this->collectFontReferences($style->definition, $fontIds);
        }

        foreach ($chapters as $chapter) {
            foreach ($chapter->alternatives as $alternative) {
                foreach ($alternative->scenes as $scene) {
                    $this->collectFontReferences($scene->content, $fontIds);
                }
            }
        }

        $fontsQuery = Font::where('owner_id', $novel->owner_id)
            ->orderBy('family')
            ->orderBy('variant');

        if (! $isOwner) {
            $fontsQuery->whereIn('id', array_values(array_unique($fontIds)));
        }

        $fonts = $fontsQuery->get(['id', 'family', 'variant', 'metadata']);

        return view('workspace.editor', [
            'novel' => $novel,
            'characterStyles' => $characterStyles->map(fn ($style) => [
                'id' => $style->id,
                'name' => $style->name,
                'definition' => $style->definition ?? [],
            ])->values(),
            'fonts' => $fonts->map(fn (Font $font) => [
                'id' => $font->id,
                'family' => $font->family,
                'variant' => $font->variant,
                'format' => (($font->metadata['format'] ?? 'ttf') === 'otf') ? 'opentype' : 'truetype',
            ])->values(),
            'chapters' => $chapters,
            'chapterList' => $chapterList,
            'documentHtml' => $documentHtml,
            'mode' => $mode,
            'selectedChapterId' => $selectedChapterId,
            'hasMore' => $hasMore,
            'nextCursor' => $nextCursor,
            'isOwner' => $isOwner,
            'editableChapterIds' => $editableChapterIds,
        ]);
    }

    private function collectFontReferences(mixed $value, array &$ids): void
    {
        if (! is_array($value)) {
            if (is_string($value) && preg_match('/^font:([1-9]\d*)$/', $value, $match) === 1) {
                $ids[] = (int) $match[1];
            }

            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && str_contains(strtolower($key), 'font')) {
                if (is_int($item) && $item > 0) {
                    $ids[] = $item;
                } elseif (is_string($item) && ctype_digit($item)) {
                    $ids[] = (int) $item;
                }
            }

            $this->collectFontReferences($item, $ids);
        }
    }

    public function sanitizePaste(Request $request, HtmlSanitizer $sanitizer)
    {
        $data = $request->validate([
            'html' => ['required', 'string', 'max:262144'],
        ]);

        $result = $sanitizer->sanitize($data['html']);

        return response()->json([
            'blocks' => $result['blocks'],
            'dropped' => $result['dropped'],
            'schema_version' => ContentDocument::SCHEMA_VERSION,
        ]);
    }
}
