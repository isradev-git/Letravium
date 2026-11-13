<?php

namespace App\Http\Controllers;

use App\Models\BookProfile;
use App\Models\Novel;
use Illuminate\Http\Request;

class BookProfileController extends Controller
{
    public function index(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);
        $profiles = $novel->hasMany(BookProfile::class)->orderBy('name')->get();

        if ($request->expectsJson()) {
            return response()->json(['profiles' => $profiles]);
        }

        return view('book.profiles', [
            'novel' => $novel,
            'profiles' => $profiles,
        ]);
    }

    public function store(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $profile = BookProfile::create(
            $request->validate($this->rules()) + ['novel_id' => $novel->id],
        );

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$novel->id}/book/profiles")->with('status', 'Perfil creado.');
        }

        return response()->json(['profile' => $profile], 201);
    }

    public function update(Request $request, BookProfile $profile)
    {
        $this->ownNovel($request, Novel::findOrFail($profile->novel_id));

        $profile->fill($request->validate($this->rules(true)))->save();

        if (! $request->expectsJson()) {
            return redirect("/workspace/novels/{$profile->novel_id}/book/profiles")->with('status', 'Perfil actualizado.');
        }

        return response()->json(['profile' => $profile->refresh()]);
    }

    public function duplicate(Request $request, BookProfile $profile)
    {
        $this->ownNovel($request, Novel::findOrFail($profile->novel_id));

        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);

        $copy = $profile->replicate();
        $copy->name = $data['name'] ?? $profile->name.' (copia)';
        $copy->save();

        return response()->json(['profile' => $copy], 201);
    }

    public function destroy(Request $request, BookProfile $profile)
    {
        $this->ownNovel($request, Novel::findOrFail($profile->novel_id));

        $profile->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';
        $present = 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'page_size' => [$present, 'string', 'max:60'],
            'orientation' => [$present, 'in:portrait,landscape'],
            'margins' => [$nullable, 'array'],
            'binding' => [$nullable, 'string', 'max:80'],
            'fonts' => [$nullable, 'array'],
            'chapter_policy' => [$nullable, 'array'],
            'notes_policy' => [$nullable, 'array'],
            'facing_pages' => [$present, 'boolean'],
            'right_page_starts' => [$present, 'boolean'],
            'running_heads' => [$nullable, 'array'],
            'widows' => [$present, 'integer', 'min:0', 'max:5'],
            'orphans' => [$present, 'integer', 'min:0', 'max:5'],
            'keep_with_next' => [$present, 'boolean'],
            'cover' => [$nullable, 'array'],
            'compositor_limits' => [$nullable, 'array'],
        ];
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
