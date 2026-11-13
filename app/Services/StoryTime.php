<?php

namespace App\Services;

use App\Models\Card;
use App\Models\StoryChange;

class StoryTime
{
    public function __construct(private int $universeId) {}

    public function resolve(Card $card, ?int $eventId): array
    {
        $order = new EventOrder($this->universeId);
        $changes = StoryChange::where('universe_id', $this->universeId)
            ->where('card_id', $card->id)
            ->orderBy('id')
            ->get();

        $applicable = [];
        $uncertain = [];

        foreach ($changes as $change) {
            $state = $this->applies($order, $change->effective_event_id, $eventId);

            if ($state === 'unknown') {
                $uncertain[$change->field][] = $this->describe($change);
            } elseif ($state === true) {
                $applicable[$change->field][] = $change;
            }
        }

        $attributes = $card->fields ?? [];
        $fields = [];

        foreach ($applicable as $field => $list) {
            [$latest, $ambiguous] = $this->latest($order, $list);

            if ($ambiguous || ! empty($uncertain[$field])) {
                $uncertain[$field] = array_merge($uncertain[$field] ?? [], array_map([$this, 'describe'], $latest));

                continue;
            }

            if ($latest !== null) {
                $value = $this->decode($latest->value);
                $attributes[$field] = $value;
                $fields[$field] = ['value' => $value];
            }
        }

        foreach ($uncertain as $field => $items) {
            $fields[$field] = [
                'value' => $attributes[$field] ?? null,
                'uncertain' => true,
                'candidates' => $items,
            ];
        }

        return [
            'story_moment' => $eventId,
            'attributes' => $attributes,
            'fields' => $fields,
            'uncertain' => array_keys($uncertain),
        ];
    }

    private function applies(EventOrder $order, ?int $changeEvent, ?int $queryEvent): bool|string
    {
        if ($changeEvent === null) {
            return true;
        }

        if ($queryEvent === null) {
            return 'unknown';
        }

        if ($changeEvent === $queryEvent) {
            return true;
        }

        return match ($order->compare($changeEvent, $queryEvent)) {
            'before', 'simultaneous' => true,
            'after' => false,
            default => 'unknown',
        };
    }

    /**
     * @param  StoryChange[]  $list
     * @return array{0: StoryChange|null, 1: bool}
     */
    private function latest(EventOrder $order, array $list): array
    {
        $maximal = [];

        foreach ($list as $candidate) {
            $dominated = false;

            foreach ($list as $other) {
                if ($other->id === $candidate->id) {
                    continue;
                }

                $comparison = $this->compareEvents($order, $other->effective_event_id, $candidate->effective_event_id);

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

        if ($maximal === []) {
            return [null, false];
        }

        $values = array_map(fn (StoryChange $change) => json_encode($this->decode($change->value)), $maximal);

        if (count(array_unique($values)) > 1) {
            return [$maximal[0], true];
        }

        return [$maximal[0], false];
    }

    private function compareEvents(EventOrder $order, ?int $a, ?int $b): string
    {
        if ($a === null && $b === null) {
            return 'simultaneous';
        }

        if ($a === null) {
            return 'before';
        }

        if ($b === null) {
            return 'after';
        }

        return $order->compare($a, $b);
    }

    private function describe(StoryChange $change): array
    {
        return [
            'change_id' => $change->id,
            'field' => $change->field,
            'value' => $this->decode($change->value),
            'event_id' => $change->effective_event_id,
            'recorded_at' => $change->created_at?->toIso8601String(),
        ];
    }

    private function decode(mixed $value): mixed
    {
        return $value;
    }
}
