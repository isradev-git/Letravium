<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Footnote;
use App\Models\Novel;
use App\Models\Scene;
use App\Services\ContentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FootnoteController extends Controller
{
    public function index(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        return response()->json([
            'footnotes' => $novel->footnotes()
                ->orderBy('kind')->orderBy('number')->get(),
        ]);
    }

    public function store(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'kind' => ['required', 'in:foot,end'],
            'text' => ['nullable', 'string', 'max:20000', 'required_without:content'],
            'content' => ['nullable', 'array', 'required_without:text'],
            'scene_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['scene_id'])) {
            $scene = Scene::find($data['scene_id']);
            if (! $scene || ! $this->belongsToNovel($scene, $novel)) {
                throw ValidationException::withMessages([
                    'scene_id' => 'La escena no pertenece a la novela.',
                ]);
            }
        }

        [$text, $content] = $this->resolveBody($data);

        $footnote = DB::transaction(function () use ($novel, $data, $text, $content) {
            $number = (int) $novel->footnotes()
                ->where('kind', $data['kind'])
                ->max('number') + 1;

            return Footnote::create([
                'novel_id' => $novel->id,
                'scene_id' => $data['scene_id'] ?? null,
                'kind' => $data['kind'],
                'number' => $number,
                'text' => $text,
                'content' => $content,
            ]);
        });

        return response()->json(['footnote' => $footnote], 201);
    }

    public function update(Request $request, Footnote $footnote)
    {
        $novel = Novel::findOrFail($footnote->novel_id);
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000', 'required_without:content'],
            'content' => ['nullable', 'array', 'required_without:text'],
        ]);

        [$text, $content] = $this->resolveBody($data);

        $footnote->fill(['text' => $text, 'content' => $content])->save();

        return response()->json(['footnote' => $footnote->refresh()]);
    }

    private function resolveBody(array $data): array
    {
        if (isset($data['content'])) {
            $document = app(ContentDocument::class)->validateNote($data['content']);

            return [app(ContentDocument::class)->text($document), $document];
        }

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages(['text' => 'La nota no puede estar vacía.']);
        }

        return [$text, null];
    }

    public function auditScene(Request $request, Scene $scene)
    {
        $novel = $this->novelForScene($scene);
        abort_unless($novel !== null, 404);

        $this->ownNovel($request, $novel);

        $referenced = [];

        foreach (($scene->content['blocks'] ?? []) as $block) {
            foreach (($block['marks'] ?? []) as $mark) {
                if (($mark['type'] ?? null) === 'footnote_reference' && isset($mark['attrs']['note_id'])) {
                    $referenced[] = (int) $mark['attrs']['note_id'];
                }
            }
        }

        $referenced = array_values(array_unique($referenced));
        $sceneNotes = Footnote::where('novel_id', $novel->id)
            ->where('scene_id', $scene->id)
            ->orderBy('kind')
            ->orderBy('number')
            ->get();
        $novelNoteIds = Footnote::where('novel_id', $novel->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json([
            'referenced' => $referenced,
            'orphaned' => $sceneNotes
                ->reject(fn (Footnote $note) => in_array((int) $note->id, $referenced, true))
                ->values()
                ->all(),
            'broken' => array_values(array_filter(
                $referenced,
                fn (int $id) => ! in_array($id, $novelNoteIds, true),
            )),
        ]);
    }

    private function belongsToNovel(Scene $scene, Novel $novel): bool
    {
        $alternative = ChapterAlternative::find($scene->chapter_alternative_id);

        return $alternative
            && Chapter::whereKey($alternative->chapter_id)
                ->where('novel_id', $novel->id)
                ->exists();
    }

    private function novelForScene(Scene $scene): ?Novel
    {
        $alternative = ChapterAlternative::find($scene->chapter_alternative_id);
        $chapter = $alternative ? Chapter::find($alternative->chapter_id) : null;

        return $chapter ? Novel::find($chapter->novel_id) : null;
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
