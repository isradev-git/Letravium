<?php

namespace App\Http\Controllers;

use App\Models\AcceptedWord;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;
use App\Models\VocabularyGrant;
use Illuminate\Http\Request;

class VocabularyController extends Controller
{
    public function indexUniverse(Request $request, Universe $universe)
    {
        $this->requireUniverseRead($request->user(), $universe);

        return response()->json(['words' => $this->words($universe->id)]);
    }

    public function storeWord(Request $request, Universe $universe)
    {
        $this->requireOwner($request->user(), $universe);

        $data = $request->validate(['word' => ['required', 'string', 'max:200']]);

        $word = AcceptedWord::firstOrCreate(
            ['universe_id' => $universe->id, 'word' => $data['word']],
            ['created_by' => $request->user()->id],
        );

        return response()->json(['word' => $word->word], 201);
    }

    public function destroyWord(Request $request, Universe $universe, string $word)
    {
        $this->requireOwner($request->user(), $universe);

        $accepted = AcceptedWord::where('universe_id', $universe->id)
            ->where('word', $word)
            ->firstOrFail();

        $accepted->delete();

        return response()->json(['removed' => true]);
    }

    public function grant(Request $request, Universe $universe)
    {
        $this->requireOwner($request->user(), $universe);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'novel_id' => ['nullable', 'integer', 'exists:novels,id'],
        ]);

        if (! empty($data['novel_id'])) {
            abort_unless(
                Novel::whereKey($data['novel_id'])->where('universe_id', $universe->id)->exists(),
                422,
            );
        }

        $grant = VocabularyGrant::firstOrCreate([
            'universe_id' => $universe->id,
            'novel_id' => $data['novel_id'] ?? null,
            'user_id' => $data['user_id'],
        ], ['granted_by' => $request->user()->id]);

        return response()->json(['grant' => $grant], 201);
    }

    public function revoke(Request $request, VocabularyGrant $grant)
    {
        $universe = Universe::findOrFail($grant->universe_id);
        $this->requireOwner($request->user(), $universe);

        $grant->delete();

        return response()->json(['revoked' => true]);
    }

    public function indexNovel(Request $request, Novel $novel)
    {
        $this->requireNovelRead($request->user(), $novel);

        return response()->json(['words' => $this->words($novel->universe_id)]);
    }

    public function check(Request $request, Novel $novel)
    {
        $this->requireNovelRead($request->user(), $novel);

        $data = $request->validate(['word' => ['required', 'string', 'max:200']]);

        $known = AcceptedWord::where('universe_id', $novel->universe_id)
            ->where('word', $data['word'])
            ->exists();

        return response()->json(['known' => $known, 'suggestions' => []]);
    }

    /**
     * @return array<int, string>
     */
    private function words(int $universeId): array
    {
        return AcceptedWord::where('universe_id', $universeId)
            ->orderBy('word')
            ->pluck('word')
            ->all();
    }

    private function requireOwner(User $user, Universe $universe): void
    {
        abort_unless((int) $universe->owner_id === (int) $user->id, 404);
    }

    private function requireUniverseRead(User $user, Universe $universe): void
    {
        if ((int) $universe->owner_id === (int) $user->id) {
            return;
        }

        abort_unless(
            VocabularyGrant::where('user_id', $user->id)->where('universe_id', $universe->id)->exists(),
            404,
        );
    }

    private function requireNovelRead(User $user, Novel $novel): void
    {
        if ((int) $novel->owner_id === (int) $user->id) {
            return;
        }

        if (Universe::whereKey($novel->universe_id)->where('owner_id', $user->id)->exists()) {
            return;
        }

        $granted = VocabularyGrant::where('user_id', $user->id)
            ->where('universe_id', $novel->universe_id)
            ->where(function ($query) use ($novel) {
                $query->whereNull('novel_id')->orWhere('novel_id', $novel->id);
            })
            ->exists();

        abort_unless($granted, 404);
    }
}
