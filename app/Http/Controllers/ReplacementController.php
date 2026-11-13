<?php

namespace App\Http\Controllers;

use App\Models\Novel;
use App\Models\Scene;
use App\Services\ReplacementService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReplacementController extends Controller
{
    public function preview(Request $request, Novel $novel, ReplacementService $service)
    {
        $data = $request->validate($this->rules());
        $preview = $service->preview($novel, $request->user(), $data);

        if (! $this->wantsHtml($request)) {
            return response()->json($preview);
        }

        $sceneIds = array_values(array_unique(array_column($preview['matches'], 'scene_id')));

        return view('workspace.replace-preview', [
            'novel' => $novel,
            'data' => $data,
            'preview' => $preview,
            'chapters' => $novel->chapters()->get(['id', 'title'])->keyBy('id'),
            'scenes' => Scene::whereIn('id', $sceneIds)->get(['id', 'title'])->keyBy('id'),
        ]);
    }

    public function apply(Request $request, Novel $novel, ReplacementService $service)
    {
        $data = $request->validate($this->rules() + [
            'preview_hash' => ['required', 'string', 'size:64'],
        ]);

        if (! $this->wantsHtml($request)) {
            return response()->json($service->apply($novel, $request->user(), $data));
        }

        try {
            $result = $service->apply($novel, $request->user(), $data);
        } catch (HttpException $exception) {
            return redirect($this->searchUrl($novel, $data))
                ->withErrors(['replace' => $exception->getMessage()]);
        }

        return redirect($this->searchUrl($novel, $data))
            ->with('status', "Sustituidas {$result['replaced']} coincidencias.");
    }

    private function searchUrl(Novel $novel, array $data): string
    {
        return "/workspace/novels/{$novel->id}/search?q=".urlencode((string) $data['search']);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:1', 'max:200'],
            'replacement' => ['required', 'string', 'max:200'],
            'chapter_ids' => ['required', 'array', 'min:1'],
            'chapter_ids.*' => ['integer'],
            'whole_word' => ['sometimes', 'boolean'],
            'case_sensitive' => ['sometimes', 'boolean'],
        ];
    }
}
