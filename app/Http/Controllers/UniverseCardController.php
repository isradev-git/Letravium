<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\CharacterRole;
use App\Models\GlossaryTerm;
use App\Models\Novel;
use App\Models\ObjectCustody;
use App\Models\PlaceContainment;
use App\Models\ResourceOrigin;
use App\Models\StoryEvent;
use App\Models\Universe;
use App\Services\CardTemplates;
use App\Services\EventOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UniverseCardController extends Controller
{
    public function templates(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        return response()->json(['templates' => CardTemplates::all()]);
    }

    public function characterRoles(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $roles = CharacterRole::where('novel_id', $novel->id)
            ->with('card')
            ->get();

        if ($this->wantsHtml($request)) {
            return view('workspace.character-roles', [
                'novel' => $novel,
                'universe' => Universe::findOrFail($novel->universe_id),
                'characters' => Card::where('universe_id', $novel->universe_id)
                    ->where('area', 'characters')
                    ->orderBy('name')
                    ->get(),
                'rolesByCard' => $roles->keyBy('card_id'),
            ]);
        }

        return response()->json(['roles' => $roles]);
    }

    public function cardContext(Request $request, Novel $novel, Card $card)
    {
        $this->ownNovel($request, $novel);
        $this->assertCardInNovel($card, $novel);

        $role = CharacterRole::where('novel_id', $novel->id)->where('card_id', $card->id)->first();

        return response()->json([
            'card' => $card->only(['id', 'area', 'name', 'template', 'fields', 'text']),
            'role' => $role,
        ]);
    }

    public function updateCharacterRole(Request $request, Novel $novel, Card $card)
    {
        $this->ownNovel($request, $novel);
        $this->assertCardInNovel($card, $novel);
        abort_unless($card->area === 'characters', 422);

        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:120'],
            'goals' => ['nullable', 'string'],
            'arc' => ['nullable', 'string'],
        ]);

        $role = CharacterRole::updateOrCreate(
            ['novel_id' => $novel->id, 'card_id' => $card->id],
            $data,
        );

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/novels/'.$novel->id.'/character-roles')
                ->with('status', 'Rol del personaje actualizado.');
        }

        return response()->json(['role' => $role]);
    }

    public function storeContainment(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'parent_card_id' => ['required', 'integer'],
            'child_card_id' => ['required', 'integer'],
        ]);

        $parent = $this->place($universe, $data['parent_card_id']);
        $child = $this->place($universe, $data['child_card_id']);

        abort_if($parent->id === $child->id, 422, 'Un lugar no puede contenerse a sí mismo.');
        abort_if($this->wouldCycle($parent->id, $child->id), 422, 'La contención crearía un ciclo.');

        $containment = DB::transaction(function () use ($universe, $parent, $child) {
            PlaceContainment::where('child_card_id', $child->id)->delete();

            return PlaceContainment::create([
                'universe_id' => $universe->id,
                'parent_card_id' => $parent->id,
                'child_card_id' => $child->id,
            ]);
        });

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/cards/'.$child->id)
                ->with('status', 'Contención actualizada.');
        }

        return response()->json(['containment' => $containment], 201);
    }

    public function placeHierarchy(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id && $card->area === 'places', 404);

        $chain = [];
        $current = $card;
        $seen = [];

        while ($current !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $chain[] = $current->only(['id', 'name']);
            $containment = PlaceContainment::where('child_card_id', $current->id)->first();
            $current = $containment ? Card::find($containment->parent_card_id) : null;
        }

        return response()->json(['hierarchy' => $chain]);
    }

    public function storeCustody(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id && $card->area === 'objects', 404);
        abort_unless(self::objectKind($card) === 'unique', 422, 'Solo un objeto único tiene custodia.');

        $data = $request->validate([
            'holder_card_id' => ['nullable', 'integer'],
            'event_id' => ['nullable', 'integer'],
            'moment' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($data['holder_card_id'])) {
            abort_unless($universe->cards()->whereKey($data['holder_card_id'])->exists(), 422);
        }

        if (isset($data['event_id'])) {
            abort_unless(StoryEvent::where('universe_id', $universe->id)->whereKey($data['event_id'])->exists(), 422);
        }

        $custody = ObjectCustody::create($data + [
            'universe_id' => $universe->id,
            'card_id' => $card->id,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/cards/'.$card->id)
                ->with('status', 'Custodia añadida.');
        }

        return response()->json(['custody' => $custody], 201);
    }

    public function custodyAt(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id && $card->area === 'objects', 404);

        $eventId = $request->integer('event_id') ?: null;
        $order = new EventOrder($universe->id);
        $candidates = [];

        foreach (ObjectCustody::where('card_id', $card->id)->orderBy('id')->get() as $custody) {
            if ($custody->event_id === null) {
                $candidates[] = $custody;

                continue;
            }

            if ($eventId === null) {
                continue;
            }

            $comparison = $custody->event_id === $eventId
                ? 'simultaneous'
                : $order->compare($custody->event_id, $eventId);

            if (in_array($comparison, ['before', 'simultaneous'], true)) {
                $candidates[] = $custody;
            }
        }

        $selected = $this->latestCustody($order, $candidates);

        return response()->json([
            'holder_card_id' => $selected?->holder_card_id,
            'moment' => $selected?->event_id,
            'uncertain' => $selected === null && $candidates !== [] && $eventId !== null,
        ]);
    }

    public function storeOrigin(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id && $card->area === 'objects', 404);
        abort_unless(self::objectKind($card) === 'resource', 422, 'Solo un recurso general tiene procedencias.');

        $data = $request->validate([
            'origin' => ['nullable', 'string', 'max:200'],
            'place_card_id' => ['nullable', 'integer'],
        ]);

        if (isset($data['place_card_id'])) {
            abort_unless($universe->cards()->whereKey($data['place_card_id'])->exists(), 422);
        }

        $origin = ResourceOrigin::create($data + [
            'universe_id' => $universe->id,
            'card_id' => $card->id,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/cards/'.$card->id)
                ->with('status', 'Procedencia añadida.');
        }

        return response()->json(['origin' => $origin], 201);
    }

    public function origins(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id && $card->area === 'objects', 404);

        return response()->json([
            'origins' => ResourceOrigin::where('card_id', $card->id)->get(),
        ]);
    }

    public function storeGlossaryTerm(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        if ($this->wantsHtml($request)) {
            $request->merge([
                'language_card_id' => $request->filled('language_card_id') ? $request->input('language_card_id') : null,
                'variants' => $this->textList($request->input('variants_text')),
                'examples' => $this->textList($request->input('examples_text')),
            ]);
        }

        $data = $request->validate([
            'language_card_id' => ['nullable', 'integer'],
            'term' => ['required', 'string', 'max:200'],
            'meaning' => ['nullable', 'string'],
            'preferred_form' => ['nullable', 'string', 'max:200'],
            'variants' => ['nullable', 'array'],
            'examples' => ['nullable', 'array'],
        ]);

        if (isset($data['language_card_id'])) {
            $language = Card::whereKey($data['language_card_id'])
                ->where('universe_id', $universe->id)
                ->where('area', 'languages')
                ->first();

            abort_unless($language !== null, 422);
        }

        $term = GlossaryTerm::create($data + ['universe_id' => $universe->id]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/glossary-terms')
                ->with('status', 'Término añadido al glosario.');
        }

        return response()->json(['term' => $term], 201);
    }

    public function glossary(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $terms = GlossaryTerm::where('universe_id', $universe->id)->orderBy('term')->get();

        if ($request->expectsJson()) {
            return response()->json(['terms' => $terms]);
        }

        return view('workspace.glossary', [
            'universe' => $universe,
            'terms' => $terms,
            'languages' => $universe->cards()->where('area', 'languages')->orderBy('name')->get(),
        ]);
    }

    private function place(Universe $universe, int $id): Card
    {
        $card = Card::whereKey($id)
            ->where('universe_id', $universe->id)
            ->where('area', 'places')
            ->first();

        abort_unless($card !== null, 422);

        return $card;
    }

    private function wouldCycle(int $parentId, int $childId): bool
    {
        $current = $parentId;
        $seen = [];

        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;
            $containment = PlaceContainment::where('child_card_id', $current)->first();

            if ($containment === null) {
                return false;
            }

            if ($containment->parent_card_id === $childId) {
                return true;
            }

            $current = $containment->parent_card_id;
        }

        return false;
    }

    private function latestCustody(EventOrder $order, array $candidates): ?ObjectCustody
    {
        $maximal = [];

        foreach ($candidates as $candidate) {
            $dominated = false;

            foreach ($candidates as $other) {
                if ($other->id === $candidate->id) {
                    continue;
                }

                $comparison = ($other->event_id === null || $candidate->event_id === null)
                    ? ($other->event_id === null ? 'before' : 'after')
                    : $order->compare($other->event_id, $candidate->event_id);

                if ($comparison === 'after'
                    || ($comparison === 'simultaneous' && $other->id > $candidate->id)) {
                    $dominated = true;
                    break;
                }
            }

            if (! $dominated) {
                $maximal[] = $candidate;
            }
        }

        return $maximal[0] ?? null;
    }

    private static function objectKind(Card $card): ?string
    {
        $kind = $card->fields['object_kind'] ?? null;

        if ($kind !== null) {
            return $kind;
        }

        return match ($card->template) {
            'unique_object', 'unique-object' => 'unique',
            'resource', 'material' => 'resource',
            default => null,
        };
    }

    private function assertCardInNovel(Card $card, Novel $novel): void
    {
        abort_unless($card->universe_id === $novel->universe_id, 404);
    }

    private function wantsHtml(Request $request): bool
    {
        return ! $request->expectsJson() || $request->query('format') === 'html';
    }

    private function textList(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[,\n]+/', $value)),
            fn (string $item) => $item !== '',
        ));
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
