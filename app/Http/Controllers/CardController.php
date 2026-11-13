<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\CardRelationship;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Novel;
use App\Models\ObjectCustody;
use App\Models\PlaceContainment;
use App\Models\ResourceOrigin;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Models\TextReference;
use App\Models\Universe;
use App\Services\CardTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardController extends Controller
{
    public function index(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $cards = $universe->cards()->orderBy('area')->orderBy('name')->get();

        if ($request->expectsJson()) {
            return response()->json(['cards' => $cards]);
        }

        $templates = CardTemplates::all();
        $selected = $request->query('area');
        $selected = is_string($selected) && in_array($selected, Card::AREAS, true)
            ? $selected
            : Card::AREAS[0];

        return view('workspace.cards', [
            'universe' => $universe,
            'cards' => $cards,
            'templates' => $templates,
            'areas' => Card::AREAS,
            'selected' => $selected,
        ]);
    }

    public function show(Request $request, Card $card)
    {
        $universe = Universe::findOrFail($card->universe_id);
        $this->ownUniverse($request, $universe);

        $relationships = CardRelationship::where('universe_id', $universe->id)
            ->where(fn ($query) => $query->where('source_card_id', $card->id)->orWhere('target_card_id', $card->id))
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['card' => $card, 'relationships' => $relationships]);
        }

        $template = CardTemplates::all()[$card->area] ?? ['label' => $card->area, 'templates' => [], 'fields' => []];
        $targets = $universe->cards()->whereKeyNot($card->id)->orderBy('area')->orderBy('name')->get();
        $relationshipCards = Card::whereIn('id', $relationships->pluck('source_card_id')->merge($relationships->pluck('target_card_id'))->unique())
            ->get()->keyBy('id');
        $references = DB::table('text_references')
            ->join('scenes', 'scenes.id', '=', 'text_references.scene_id')
            ->join('chapter_alternatives', 'chapter_alternatives.id', '=', 'scenes.chapter_alternative_id')
            ->join('chapters', 'chapters.id', '=', 'chapter_alternatives.chapter_id')
            ->join('novels', 'novels.id', '=', 'chapters.novel_id')
            ->where('text_references.card_id', $card->id)
            ->where('novels.owner_id', $request->user()->id)
            ->orderBy('novels.id')->orderBy('chapters.position')->orderBy('scenes.position')
            ->get([
                'scenes.id as scene_id',
                'scenes.title as scene_title',
                'chapters.id as chapter_id',
                'chapters.title as chapter_title',
                'novels.id as novel_id',
                'novels.title as novel_title',
            ]);

        $customFields = DB::table('card_field_definitions')
            ->where('universe_id', $universe->id)
            ->whereNull('removed_at')
            ->orderBy('label')
            ->get()
            ->map(fn ($field) => [
                'id' => $field->id,
                'key' => $field->key,
                'type' => $field->type,
                'label' => $field->label,
                'options' => json_decode($field->options ?? '[]', true) ?? [],
                'temporal' => (bool) $field->temporal,
            ])
            ->all();

        return view('workspace.card', [
            'universe' => $universe,
            'card' => $card,
            'template' => $template,
            'customFields' => $customFields,
            'relationships' => $relationships,
            'targets' => $targets,
            'relationshipCards' => $relationshipCards,
            'references' => $references,
            ...$this->cardLinks($card, $universe),
        ]);
    }

    public function store(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'area' => ['required', 'in:'.implode(',', Card::AREAS)],
            'name' => ['required', 'string', 'max:200'],
            'template' => ['nullable', 'string', 'max:64'],
            'fields' => ['nullable', 'array'],
            'text' => ['nullable', 'array'],
        ]);

        $card = Card::create($data + ['universe_id' => $universe->id]);

        if (! $request->expectsJson()) {
            return redirect('/workspace/cards/'.$card->id)->with('status', 'Ficha creada.');
        }

        return response()->json(['card' => $card], 201);
    }

    public function update(Request $request, Card $card)
    {
        $this->ownUniverse($request, Universe::findOrFail($card->universe_id));
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'fields' => ['nullable', 'array'],
            'present_fields' => ['nullable', 'array'],
            'present_fields.*' => ['string', 'max:100'],
            'text' => ['nullable', 'array'],
            'template' => ['nullable', 'string', 'max:64'],
        ]);

        if (isset($data['fields']) || isset($data['present_fields'])) {
            $fields = is_array($card->fields) ? $card->fields : [];

            foreach ($data['present_fields'] ?? [] as $key) {
                if (! array_key_exists($key, $data['fields'] ?? [])) {
                    $fields[$key] = null;
                }
            }

            $data['fields'] = array_merge($fields, $data['fields'] ?? []);
        }

        $card->fill($data)->save();

        if (! $request->expectsJson()) {
            return redirect('/workspace/cards/'.$card->id)->with('status', 'Ficha guardada.');
        }

        return response()->json(['card' => $card->refresh()]);
    }

    public function relationships(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        return response()->json([
            'relationships' => CardRelationship::where('universe_id', $universe->id)->get(),
        ]);
    }

    public function storeRelationship(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'source_card_id' => ['required', 'integer'],
            'target_card_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:200'],
            'directed' => ['boolean'],
        ]);

        abort_unless(
            $universe->cards()->whereKey($data['source_card_id'])->exists()
            && $universe->cards()->whereKey($data['target_card_id'])->exists(),
            422,
        );

        $relationship = CardRelationship::create($data + [
            'universe_id' => $universe->id,
            'directed' => $data['directed'] ?? true,
        ]);

        if (! $request->expectsJson()) {
            return redirect('/workspace/cards/'.$data['source_card_id'])->with('status', 'Relación creada.');
        }

        return response()->json(['relationship' => $relationship], 201);
    }

    public function references(Request $request, Scene $scene)
    {
        $this->ownScene($request, $scene);
        $references = TextReference::where('scene_id', $scene->id)->get();

        if ($request->expectsJson()) {
            return response()->json(['references' => $references]);
        }

        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $novel = Novel::findOrFail($chapter->novel_id);
        $universe = Universe::findOrFail($novel->universe_id);
        $cards = $universe->cards()->orderBy('area')->orderBy('name')->get();
        $referencedCards = Card::whereIn('id', $references->pluck('card_id'))->get()->keyBy('id');

        return view('workspace.scene-references', [
            'scene' => $scene,
            'novel' => $novel,
            'chapter' => $chapter,
            'universe' => $universe,
            'cards' => $cards,
            'references' => $references,
            'referencedCards' => $referencedCards,
        ]);
    }

    public function storeReference(Request $request, Scene $scene)
    {
        $this->ownScene($request, $scene);
        $data = $request->validate(['card_id' => ['required', 'integer']]);

        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $novel = Novel::findOrFail(Chapter::findOrFail($alternative->chapter_id)->novel_id);
        $card = Card::findOrFail($data['card_id']);
        abort_unless($card->universe_id === $novel->universe_id, 422);

        $reference = TextReference::firstOrCreate([
            'scene_id' => $scene->id,
            'card_id' => $card->id,
        ], ['created_by' => $request->user()->id]);

        if (! $request->expectsJson()) {
            return redirect('/workspace/scenes/'.$scene->id.'/references')->with('status', 'Referencia añadida.');
        }

        return response()->json(['reference' => $reference], 201);
    }

    private function cardLinks(Card $card, Universe $universe): array
    {
        $isPlace = $card->area === 'places';
        $objectKind = $card->area === 'objects' ? $this->objectKind($card) : null;

        $containmentChain = [];

        if ($isPlace) {
            $seen = [];
            $current = $card;

            while ($current !== null && ! isset($seen[$current->id])) {
                $seen[$current->id] = true;
                $containmentChain[] = $current->name;
                $containment = PlaceContainment::where('child_card_id', $current->id)->first();
                $current = $containment ? Card::find($containment->parent_card_id) : null;
            }

            $containmentChain = array_reverse($containmentChain);
        }

        $parentOptions = collect();
        $custodyHistory = collect();
        $holderNames = collect();
        $eventNames = collect();
        $holders = collect();
        $events = collect();
        $origins = collect();
        $originPlaceNames = collect();
        $originPlaces = collect();

        if ($isPlace) {
            $descendants = [];
            $queue = [$card->id];

            while ($queue !== []) {
                $parentId = array_shift($queue);

                foreach (PlaceContainment::where('parent_card_id', $parentId)->pluck('child_card_id') as $childId) {
                    if (! in_array($childId, $descendants, true)) {
                        $descendants[] = $childId;
                        $queue[] = $childId;
                    }
                }
            }

            $parentOptions = Card::where('universe_id', $universe->id)
                ->where('area', 'places')
                ->whereNotIn('id', array_merge([$card->id], $descendants))
                ->orderBy('name')
                ->get();
        }

        if ($objectKind === 'unique') {
            $custodyHistory = ObjectCustody::where('card_id', $card->id)->orderBy('id')->get();
            $holderNames = Card::whereIn('id', $custodyHistory->pluck('holder_card_id')->filter()->unique())->pluck('name', 'id');
            $eventNames = StoryEvent::whereIn('id', $custodyHistory->pluck('event_id')->filter()->unique())->pluck('name', 'id');
            $holders = Card::where('universe_id', $universe->id)->orderBy('area')->orderBy('name')->get();
            $events = StoryEvent::where('universe_id', $universe->id)->orderBy('name')->get();
        }

        if ($objectKind === 'resource') {
            $origins = ResourceOrigin::where('card_id', $card->id)->orderBy('id')->get();
            $originPlaceNames = Card::whereIn('id', $origins->pluck('place_card_id')->filter()->unique())->pluck('name', 'id');
            $originPlaces = Card::where('universe_id', $universe->id)->where('area', 'places')->orderBy('name')->get();
        }

        return compact(
            'isPlace',
            'objectKind',
            'containmentChain',
            'parentOptions',
            'custodyHistory',
            'holderNames',
            'eventNames',
            'holders',
            'events',
            'origins',
            'originPlaceNames',
            'originPlaces',
        );
    }

    private function objectKind(Card $card): ?string
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

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function ownScene(Request $request, Scene $scene): void
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $novel = Novel::findOrFail(Chapter::findOrFail($alternative->chapter_id)->novel_id);
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
