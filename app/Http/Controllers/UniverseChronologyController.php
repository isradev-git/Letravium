<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\CalendarConversion;
use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\ContinuityDismissal;
use App\Models\EventRelation;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryChange;
use App\Models\StoryContext;
use App\Models\StoryEvent;
use App\Models\Universe;
use App\Services\ContinuityWarnings;
use App\Services\EventOrder;
use App\Services\StoryTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UniverseChronologyController extends Controller
{
    public function events(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $events = StoryEvent::where('universe_id', $universe->id)->orderBy('id')->get();
        $relations = EventRelation::where('universe_id', $universe->id)->get();

        if ($request->expectsJson()) {
            return response()->json(['events' => $events, 'relations' => $relations]);
        }

        return $this->chronologyView($universe, [
            'events' => $events,
            'relations' => $relations,
        ]);
    }

    public function storeEvent(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['nullable', 'in:event,epoch'],
            'description' => ['nullable', 'string'],
            'moment' => ['nullable', 'string', 'max:200'],
            'participants' => ['nullable', 'array'],
            'places' => ['nullable', 'array'],
        ]);

        $event = StoryEvent::create($data + [
            'universe_id' => $universe->id,
            'type' => $data['type'] ?? 'event',
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/events')
                ->with('status', 'Evento añadido a la cronología.');
        }

        return response()->json(['event' => $event], 201);
    }

    public function storeRelation(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'from_event_id' => ['required', 'integer'],
            'to_event_id' => ['required', 'integer'],
            'type' => ['required', 'in:before,after,simultaneous'],
        ]);

        $from = $this->event($universe, $data['from_event_id']);
        $to = $this->event($universe, $data['to_event_id']);
        $relation = $data['type'];

        if ($relation === 'after') {
            [$from, $to] = [$to, $from];
            $relation = 'before';
        }

        $order = new EventOrder($universe->id);
        $error = $order->validate($relation, $from->id, $to->id);

        if ($error !== null) {
            return response()->json(['error' => 'contradictory_relation'], 422);
        }

        $edge = EventRelation::create([
            'universe_id' => $universe->id,
            'from_event_id' => $from->id,
            'to_event_id' => $to->id,
            'relation' => $relation,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/events')
                ->with('status', 'Relación de orden añadida.');
        }

        return response()->json(['relation' => $edge], 201);
    }

    public function compareEvent(Request $request, Universe $universe, StoryEvent $event)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($event->universe_id === $universe->id, 404);

        $html = $this->wantsHtml($request);
        $data = $request->validate(['other_event_id' => [$html ? 'nullable' : 'required', 'integer']]);
        $other = isset($data['other_event_id']) ? $this->event($universe, $data['other_event_id']) : null;
        $relation = $other !== null ? (new EventOrder($universe->id))->compare($event->id, $other->id) : null;

        if ($html) {
            return $this->chronologyView($universe, [
                'comparedEvent' => $event,
                'otherEvent' => $other,
                'comparison' => $relation,
            ]);
        }

        return response()->json(['relation' => $relation]);
    }

    public function calendars(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $calendars = Calendar::where('universe_id', $universe->id)->orderBy('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['calendars' => $calendars]);
        }

        return $this->calendarsView($universe);
    }

    public function storeCalendar(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'config' => ['nullable', 'array'],
        ]);

        $calendar = DB::transaction(function () use ($universe, $data) {
            Calendar::where('universe_id', $universe->id)->where('is_current', true)->update([
                'is_current' => false,
                'superseded_at' => now(),
            ]);

            return Calendar::create($data + [
                'universe_id' => $universe->id,
                'is_current' => true,
            ]);
        });

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('status', 'Calendario creado.');
        }

        return response()->json(['calendar' => $calendar], 201);
    }

    public function storeConversion(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'from_calendar_id' => ['required', 'integer'],
            'to_calendar_id' => ['required', 'integer'],
            'from_label' => ['required', 'string', 'max:200'],
            'to_label' => ['required', 'string', 'max:200'],
        ]);

        $this->calendar($universe, $data['from_calendar_id']);
        $this->calendar($universe, $data['to_calendar_id']);

        $conversion = CalendarConversion::create($data + ['universe_id' => $universe->id]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('status', 'Equivalencia entre calendarios registrada.');
        }

        return response()->json(['conversion' => $conversion], 201);
    }

    public function resolveConversion(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'from_calendar_id' => ['required', 'integer'],
            'to_calendar_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:200'],
        ]);

        $conversion = CalendarConversion::where('universe_id', $universe->id)
            ->where('from_calendar_id', $data['from_calendar_id'])
            ->where('to_calendar_id', $data['to_calendar_id'])
            ->where('from_label', $data['label'])
            ->first();

        if ($conversion === null) {
            return response()->json(['error' => 'no_authored_equivalence'], 422);
        }

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('conversion_result', $conversion->to_label)
                ->with('status', 'Conversión resuelta.');
        }

        return response()->json(['converted' => $conversion->to_label]);
    }

    public function storeStoryChange(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id, 404);

        $data = $request->validate([
            'field' => ['required', 'string', 'max:64'],
            'value' => ['nullable'],
            'event_id' => ['nullable', 'integer'],
            'moment' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($data['event_id'])) {
            $this->event($universe, $data['event_id']);
        }

        $change = StoryChange::create([
            'universe_id' => $universe->id,
            'card_id' => $card->id,
            'field' => $data['field'],
            'value' => $data['value'] ?? null,
            'effective_event_id' => $data['event_id'] ?? null,
            'moment' => $data['moment'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('status', 'Cambio de historia registrado.');
        }

        return response()->json(['change' => $change], 201);
    }

    public function storyTime(Request $request, Universe $universe, Card $card)
    {
        $this->ownUniverse($request, $universe);
        abort_unless($card->universe_id === $universe->id, 404);

        $eventId = $request->integer('event_id') ?: null;

        if ($eventId !== null) {
            $this->event($universe, $eventId);
        }

        $storyTime = (new StoryTime($universe->id))->resolve($card, $eventId);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('story_time', $storyTime)
                ->with('status', 'Tiempo de historia resuelto para '.$card->name.'.');
        }

        return response()->json($storyTime);
    }

    public function storeStoryContext(Request $request, Scene $scene)
    {
        [$novel, $universe] = $this->ownScene($request, $scene);
        $data = $request->validate([
            'card_id' => ['required', 'integer'],
            'event_id' => ['nullable', 'integer'],
        ]);

        $card = Card::whereKey($data['card_id'])->where('universe_id', $universe->id)->first();
        abort_unless($card !== null, 422);

        if (isset($data['event_id'])) {
            $this->event($universe, $data['event_id']);
        }

        $context = (new StoryTime($universe->id))->resolve($card, $data['event_id'] ?? null);

        $snapshot = StoryContext::create([
            'universe_id' => $universe->id,
            'novel_id' => $novel->id,
            'scene_id' => $scene->id,
            'card_id' => $card->id,
            'event_id' => $data['event_id'] ?? null,
            'context' => $context,
            'captured_at' => now(),
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/calendars')
                ->with('status', 'Contexto temporal de la escena capturado.');
        }

        return response()->json(['context' => $snapshot], 201);
    }

    public function showStoryContext(Request $request, Scene $scene)
    {
        [$novel] = $this->ownScene($request, $scene);
        $snapshot = StoryContext::where('scene_id', $scene->id)->orderByDesc('id')->first();

        return response()->json(['context' => $snapshot]);
    }

    public function warnings(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $warnings = (new ContinuityWarnings($universe, $request->user()->id))->scan();

        if ($request->expectsJson()) {
            return response()->json(['warnings' => $warnings]);
        }

        return $this->calendarsView($universe, ['warnings' => $warnings]);
    }

    public function dismissWarning(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'max:64']]);

        ContinuityDismissal::updateOrCreate([
            'universe_id' => $universe->id,
            'user_id' => $request->user()->id,
            'fingerprint' => $data['fingerprint'],
        ], ['dismissed_at' => now()]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/continuity-warnings')
                ->with('status', 'Aviso de continuidad descartado.');
        }

        return response()->json(['status' => 'dismissed']);
    }

    private function chronologyView(Universe $universe, array $data = [])
    {
        return view('workspace.chronology', $data + [
            'universe' => $universe,
            'events' => StoryEvent::where('universe_id', $universe->id)->orderBy('id')->get(),
            'relations' => EventRelation::where('universe_id', $universe->id)->get(),
            'cards' => $universe->cards()->orderBy('name')->get(),
        ]);
    }

    private function calendarsView(Universe $universe, array $data = [])
    {
        return view('workspace.calendars', $data + [
            'universe' => $universe,
            'calendars' => Calendar::where('universe_id', $universe->id)->orderBy('id')->get(),
            'conversions' => CalendarConversion::where('universe_id', $universe->id)->orderBy('id')->get(),
            'cards' => $universe->cards()->orderBy('name')->get(),
            'events' => StoryEvent::where('universe_id', $universe->id)->orderBy('id')->get(),
            'warnings' => null,
        ]);
    }

    private function event(Universe $universe, int $id): StoryEvent
    {
        $event = StoryEvent::whereKey($id)->where('universe_id', $universe->id)->first();
        abort_unless($event !== null, 422);

        return $event;
    }

    private function calendar(Universe $universe, int $id): Calendar
    {
        $calendar = Calendar::whereKey($id)->where('universe_id', $universe->id)->first();
        abort_unless($calendar !== null, 422);

        return $calendar;
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function wantsHtml(Request $request): bool
    {
        return ! $request->expectsJson() || $request->query('format') === 'html';
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }

    /**
     * @return array{0: Novel, 1: Universe}
     */
    private function ownScene(Request $request, Scene $scene): array
    {
        $alternative = ChapterAlternative::findOrFail($scene->chapter_alternative_id);
        $chapter = Chapter::findOrFail($alternative->chapter_id);
        $novel = Novel::findOrFail($chapter->novel_id);
        $this->ownNovel($request, $novel);

        return [$novel, Universe::findOrFail($novel->universe_id)];
    }
}
