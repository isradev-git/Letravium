<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\ContinuityDismissal;
use App\Models\EventRelation;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\ObjectCustody;
use App\Models\Scene;
use App\Models\StoryChange;
use App\Models\StoryEvent;
use App\Models\TextReference;
use App\Models\Universe;
use Illuminate\Support\Facades\DB;

class ContinuityWarnings
{
    public function __construct(private Universe $universe, private int $userId) {}

    public function scan(): array
    {
        $warnings = array_merge(
            $this->birthDeathWarnings(),
            $this->ownershipWarnings(),
            $this->removedLinkWarnings(),
            $this->timeWarnings(),
        );

        $dismissed = ContinuityDismissal::where('universe_id', $this->universe->id)
            ->where('user_id', $this->userId)
            ->pluck('fingerprint')
            ->all();

        return array_values(array_filter(
            $warnings,
            fn (array $warning) => ! in_array($warning['fingerprint'], $dismissed, true),
        ));
    }

    private function birthDeathWarnings(): array
    {
        $warnings = [];
        $order = new EventOrder($this->universe->id);
        $events = StoryEvent::where('universe_id', $this->universe->id)->pluck('id')->all();

        $scenes = DB::table('scenes')
            ->join('chapter_alternatives', 'chapter_alternatives.id', '=', 'scenes.chapter_alternative_id')
            ->join('chapters', 'chapters.id', '=', 'chapter_alternatives.chapter_id')
            ->join('novels', 'novels.id', '=', 'chapters.novel_id')
            ->where('novels.universe_id', $this->universe->id)
            ->select('scenes.id', 'scenes.narrative_moment', 'scenes.pov_card_id')
            ->get();

        $characters = Card::where('universe_id', $this->universe->id)
            ->where('area', 'characters')
            ->get();

        foreach ($scenes as $scene) {
            if (! is_numeric($scene->narrative_moment)) {
                continue;
            }

            $moment = (int) $scene->narrative_moment;

            if (! in_array($moment, $events, true)) {
                continue;
            }

            $cardIds = TextReference::where('scene_id', $scene->id)->pluck('card_id')->all();

            if ($scene->pov_card_id) {
                $cardIds[] = (int) $scene->pov_card_id;
            }

            foreach ($characters as $character) {
                if (! in_array($character->id, $cardIds, true)) {
                    continue;
                }

                $birth = $this->eventValue($character, 'birth_event');
                $death = $this->eventValue($character, 'death_event');

                if ($birth && $order->compare($moment, $birth) === 'before') {
                    $warnings[] = $this->warning('scene_before_birth', [
                        'scene_id' => $scene->id,
                        'card_id' => $character->id,
                        'birth_event_id' => $birth,
                    ], 'La escena transcurre antes del nacimiento registrado del personaje.');
                }

                if ($death && $order->compare($moment, $death) === 'after') {
                    $warnings[] = $this->warning('scene_after_death', [
                        'scene_id' => $scene->id,
                        'card_id' => $character->id,
                        'death_event_id' => $death,
                    ], 'La escena transcurre después de la muerte registrada del personaje.');
                }
            }
        }

        return $warnings;
    }

    private function ownershipWarnings(): array
    {
        $warnings = [];
        $groups = [];

        foreach (ObjectCustody::where('universe_id', $this->universe->id)->get() as $custody) {
            $key = $custody->event_id !== null ? 'e'.$custody->event_id : 'm'.$custody->moment;
            $groups[$custody->card_id][$key][] = $custody;
        }

        foreach ($groups as $cardId => $moments) {
            foreach ($moments as $key => $custodies) {
                $holders = array_values(array_unique(array_filter(array_map(
                    fn (ObjectCustody $custody) => $custody->holder_card_id,
                    $custodies,
                ))));

                if (count($holders) > 1) {
                    $warnings[] = $this->warning('incompatible_ownership', [
                        'card_id' => (int) $cardId,
                        'moment' => $key,
                        'holders' => $holders,
                    ], 'El mismo objeto único figura en manos de varios tenedores en el mismo momento.');
                }
            }
        }

        return $warnings;
    }

    private function removedLinkWarnings(): array
    {
        $warnings = [];
        $noteIds = Note::where('universe_id', $this->universe->id)->pluck('id')->all();

        foreach (NoteLink::whereIn('note_id', $noteIds)->get() as $link) {
            $exists = match ($link->linkable_type) {
                'card' => Card::whereKey($link->linkable_id)->exists(),
                'scene' => Scene::whereKey($link->linkable_id)->exists(),
                'chapter' => Chapter::whereKey($link->linkable_id)->exists(),
                default => false,
            };

            if (! $exists) {
                $warnings[] = $this->warning('removed_link', [
                    'note_id' => $link->note_id,
                    'linkable_type' => $link->linkable_type,
                    'linkable_id' => $link->linkable_id,
                ], 'Una nota apunta a un elemento retirado y debe recolocarse.');
            }
        }

        return $warnings;
    }

    private function timeWarnings(): array
    {
        $warnings = [];
        $order = new EventOrder($this->universe->id);

        foreach (EventRelation::where('universe_id', $this->universe->id)->where('relation', 'before')->get() as $relation) {
            if ($order->compare($relation->from_event_id, $relation->to_event_id) !== 'before') {
                $warnings[] = $this->warning('contradictory_time', [
                    'from_event_id' => $relation->from_event_id,
                    'to_event_id' => $relation->to_event_id,
                ], 'Dos relaciones temporales registradas se contradicen.');
            }
        }

        return $warnings;
    }

    private function eventValue(Card $card, string $field): ?int
    {
        $change = StoryChange::where('card_id', $card->id)
            ->where('field', $field)
            ->orderByDesc('id')
            ->first();

        if (! $change) {
            return null;
        }

        $value = $change->value;

        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function warning(string $code, array $facts, string $message): array
    {
        ksort($facts);

        return [
            'code' => $code,
            'message' => $message,
            'facts' => $facts,
            'fingerprint' => sha1($code.'|'.json_encode($facts)),
        ];
    }
}
