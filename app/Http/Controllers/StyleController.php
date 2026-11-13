<?php

namespace App\Http\Controllers;

use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Style;
use App\Services\HistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StyleController extends Controller
{
    public function index(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        return response()->json([
            'styles' => $novel->styles()->orderBy('kind')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('styles')->where(fn ($query) => $query
                    ->where('novel_id', $novel->id)
                    ->where('kind', $request->input('kind'))),
            ],
            'kind' => ['required', 'in:paragraph,character'],
            'definition' => ['required', 'array'],
        ]);

        $style = Style::create($data + ['novel_id' => $novel->id]);

        return response()->json(['style' => $style], 201);
    }

    public function update(Request $request, Style $style)
    {
        $novel = Novel::findOrFail($style->novel_id);
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('styles')->where(fn ($query) => $query
                    ->where('novel_id', $style->novel_id)
                    ->where('kind', $style->kind))->ignore($style->id),
            ],
            'definition' => ['sometimes', 'required', 'array'],
        ]);

        $style->fill($data)->save();

        return response()->json(['style' => $style->refresh()]);
    }

    public function duplicate(Request $request, Style $style)
    {
        $novel = Novel::findOrFail($style->novel_id);
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('styles')->where(fn ($query) => $query
                    ->where('novel_id', $style->novel_id)
                    ->where('kind', $style->kind)),
            ],
        ]);

        $copy = $style->replicate();
        $copy->name = $data['name'];
        $copy->save();

        return response()->json(['style' => $copy], 201);
    }

    public function destroy(Request $request, Style $style)
    {
        $novel = Novel::findOrFail($style->novel_id);
        $this->ownNovel($request, $novel);

        $data = $request->validate(['replacement_id' => ['nullable', 'integer']]);

        $used = $this->usedBy($novel, $style->id);
        $replacement = null;

        if ($used !== []) {
            if (empty($data['replacement_id'])) {
                return response()->json([
                    'message' => 'El estilo está en uso; indica replacement_id.',
                    'dependencies' => $used,
                ], 422);
            }

            $replacement = Style::whereKey($data['replacement_id'])
                ->where('novel_id', $novel->id)
                ->where('kind', $style->kind)
                ->first();

            if (! $replacement || (int) $replacement->id === (int) $style->id) {
                return response()->json([
                    'message' => 'replacement_id no es un estilo compatible de la novela.',
                ], 422);
            }
        }

        DB::transaction(function () use ($novel, $style, $replacement) {
            if ($replacement) {
                $history = app(HistoryService::class);

                foreach ($this->scenes($novel) as $scene) {
                    $content = $scene->content;
                    if (! is_array($content)) {
                        continue;
                    }
                    $count = 0;
                    $this->walkStyleRefs($content, $style->id, true, $replacement->id, $count);
                    if ($count > 0) {
                        $history->record((int) $scene->id, (int) $scene->revision, $scene->content ?? [], (int) $novel->owner_id, 'style_replace');
                        $scene->forceFill([
                            'content' => $content,
                            'revision' => (int) $scene->revision + 1,
                        ])->save();
                    }
                }
            }

            $style->delete();
        });

        return response()->json([
            'status' => 'deleted',
            'replacement_id' => $replacement?->id,
        ]);
    }

    private function usedBy(Novel $novel, int $styleId): array
    {
        $used = [];

        foreach ($this->scenes($novel) as $scene) {
            $content = $scene->content;
            if (! is_array($content)) {
                continue;
            }
            $count = 0;
            $this->walkStyleRefs($content, $styleId, false, 0, $count);
            if ($count > 0) {
                $used[] = ['scene_id' => $scene->id, 'uses' => $count];
            }
        }

        return $used;
    }

    private function scenes(Novel $novel)
    {
        $chapterIds = $novel->chapters()->pluck('id');
        $alternativeIds = ChapterAlternative::whereIn('chapter_id', $chapterIds)->pluck('id');

        return Scene::whereIn('chapter_alternative_id', $alternativeIds)->get();
    }

    private function walkStyleRefs(array &$content, int $styleId, bool $replace, int $replacementId, int &$count): void
    {
        foreach ($content as $key => &$value) {
            $isStyleKey = is_string($key) && str_contains(strtolower($key), 'style');

            if ($isStyleKey) {
                if ($value === $styleId) {
                    $count++;
                    if ($replace) {
                        $value = $replacementId;
                    }
                } elseif (is_array($value)) {
                    foreach ($value as &$item) {
                        if ($item === $styleId) {
                            $count++;
                            if ($replace) {
                                $item = $replacementId;
                            }
                        }
                    }
                    unset($item);
                }
            }

            if (is_array($value)) {
                if (($value['type'] ?? null) === 'character_style'
                    && (string) ($value['attrs']['id'] ?? '') === (string) $styleId) {
                    $count++;

                    if ($replace) {
                        $value['attrs']['id'] = (string) $replacementId;
                    }
                }

                $this->walkStyleRefs($value, $styleId, $replace, $replacementId, $count);
            }
        }
        unset($value);
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
