<?php

namespace App\Http\Controllers;

use App\Models\BookMark;
use App\Models\BookMetadata;
use App\Models\BookSection;
use App\Models\Novel;
use App\Services\BookNavigation;
use App\Services\BookPreflight;
use Illuminate\Http\Request;

class BookSetupController extends Controller
{
    public function metadata(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $metadata = BookMetadata::firstOrNew(['novel_id' => $novel->id], [
            'title' => $novel->title,
            'language' => 'es',
        ]);
        $catalog = $this->catalog();

        if ($request->expectsJson()) {
            return response()->json(['metadata' => $metadata, 'catalog' => $catalog]);
        }

        return view('book.metadata', [
            'novel' => $novel,
            'metadata' => $metadata,
            'catalog' => $catalog,
        ]);
    }

    public function updateMetadata(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:16'],
            'description' => ['nullable', 'string'],
            'rights' => ['nullable', 'string', 'max:500'],
            'edition' => ['nullable', 'string', 'max:120'],
            'publisher' => ['nullable', 'string', 'max:200'],
            'isbn' => ['nullable', 'string', 'max:32'],
            'extra' => ['nullable', 'array'],
        ]);

        $metadata = BookMetadata::updateOrCreate(['novel_id' => $novel->id], $data);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}/book/metadata")->with('status', 'Metadatos guardados.');
        }

        return response()->json(['metadata' => $metadata]);
    }

    public function sections(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $sections = $novel->hasMany(BookSection::class)->orderBy('area')->orderBy('position')->get();

        if ($request->expectsJson()) {
            return response()->json(['sections' => $sections]);
        }

        return view('book.sections', [
            'novel' => $novel,
            'sections' => $sections,
            'catalog' => $this->catalog(),
        ]);
    }

    public function storeSection(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate($this->sectionRules());

        $section = BookSection::create($data + [
            'novel_id' => $novel->id,
            'position' => (int) $novel->hasMany(BookSection::class)->max('position') + 1,
        ]);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}/book/sections")->with('status', 'Sección creada.');
        }

        return response()->json(['section' => $section], 201);
    }

    public function updateSection(Request $request, BookSection $section)
    {
        $this->ownNovel($request, Novel::findOrFail($section->novel_id));

        $section->fill($request->validate($this->sectionRules(true)))->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$section->novel_id}/book/sections")->with('status', 'Sección actualizada.');
        }

        return response()->json(['section' => $section->refresh()]);
    }

    public function destroySection(Request $request, BookSection $section)
    {
        $this->ownNovel($request, Novel::findOrFail($section->novel_id));

        $section->delete();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$section->novel_id}/book/sections")->with('status', 'Sección eliminada.');
        }

        return response()->json(['status' => 'deleted']);
    }

    public function marks(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        return response()->json([
            'marks' => $novel->hasMany(BookMark::class)->orderBy('type')->orderBy('position')->get(),
        ]);
    }

    public function storeMark(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate($this->markRules());

        $mark = BookMark::create($data + [
            'novel_id' => $novel->id,
            'position' => (int) $novel->hasMany(BookMark::class)->max('position') + 1,
        ]);

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}/book/navigation")->with('status', 'Marca creada.');
        }

        return response()->json(['mark' => $mark], 201);
    }

    public function updateMark(Request $request, BookMark $mark)
    {
        $this->ownNovel($request, Novel::findOrFail($mark->novel_id));

        $mark->fill($request->validate($this->markRules(true)))->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$mark->novel_id}/book/navigation")->with('status', 'Marca actualizada.');
        }

        return response()->json(['mark' => $mark->refresh()]);
    }

    public function destroyMark(Request $request, BookMark $mark)
    {
        $this->ownNovel($request, Novel::findOrFail($mark->novel_id));

        $novelId = $mark->novel_id;
        $mark->delete();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novelId}/book/navigation")->with('status', 'Marca eliminada.');
        }

        return response()->json(['status' => 'deleted']);
    }

    public function navigation(Request $request, Novel $novel, BookNavigation $navigation)
    {
        $this->ownNovel($request, $novel);
        $built = $navigation->build($novel);

        if ($request->expectsJson()) {
            return response()->json(['navigation' => $built]);
        }

        return view('book.navigation', [
            'novel' => $novel,
            'navigation' => $built,
            'marks' => $novel->hasMany(BookMark::class)->orderBy('type')->orderBy('position')->get(),
        ]);
    }

    public function preflight(Request $request, Novel $novel, BookPreflight $preflight)
    {
        $this->ownNovel($request, $novel);

        return response()->json(['preflight' => $preflight->run($novel)]);
    }

    private function catalog(): array
    {
        return [
            'front' => ['title_page', 'credits', 'dedication', 'prologue', 'introduction'],
            'back' => ['epilogue', 'acknowledgments', 'bibliography', 'glossary', 'about_author'],
        ];
    }

    private function sectionRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';

        return [
            'area' => [$required, 'in:front,back'],
            'type' => [$required, 'string', 'max:40'],
            'title' => [$nullable, 'string', 'max:255'],
            'content' => [$nullable, 'string'],
            'include_in_export' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function markRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';

        return [
            'type' => [$required, 'in:figure,table,index,glossary'],
            'label' => [$nullable, 'string', 'max:255'],
            'caption' => [$nullable, 'string'],
            'target_type' => [$nullable, 'string', 'max:32'],
            'target_id' => [$nullable, 'integer', 'min:1'],
            'sort_key' => [$nullable, 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'data' => [$nullable, 'array'],
        ];
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
