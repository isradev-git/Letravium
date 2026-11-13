<?php

namespace App\Services;

use App\Models\EventRelation;

class EventOrder
{
    public function __construct(private int $universeId) {}

    public function compare(int $a, int $b): string
    {
        if ($a === $b) {
            return 'simultaneous';
        }

        [$classes, $before] = $this->graph();
        $ca = $classes[$a] ?? $a;
        $cb = $classes[$b] ?? $b;

        if ($ca === $cb) {
            return 'simultaneous';
        }

        if ($this->reachable($before, $ca, $cb)) {
            return 'before';
        }

        if ($this->reachable($before, $cb, $ca)) {
            return 'after';
        }

        return 'unknown';
    }

    /**
     * Returns null when the relation can be stored, or an error key when it contradicts existing data.
     */
    public function validate(string $relation, int $from, int $to): ?string
    {
        if ($from === $to) {
            return $relation === 'before' ? 'contradictory' : null;
        }

        [$classes, $before] = $this->graph();
        $cf = $classes[$from] ?? $from;
        $ct = $classes[$to] ?? $to;

        if ($relation === 'before') {
            if ($cf === $ct || $this->reachable($before, $ct, $cf)) {
                return 'contradictory';
            }

            return null;
        }

        if ($cf === $ct) {
            return null;
        }

        if ($this->reachable($before, $cf, $ct) || $this->reachable($before, $ct, $cf)) {
            return 'contradictory';
        }

        return null;
    }

    private function graph(): array
    {
        $relations = EventRelation::where('universe_id', $this->universeId)->get();
        $parent = [];

        $find = function (int $x) use (&$find, &$parent): int {
            $parent[$x] ??= $x;

            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };

        $union = function (int $a, int $b) use (&$find, &$parent): void {
            $ra = $find($a);
            $rb = $find($b);

            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        foreach ($relations as $relation) {
            $find($relation->from_event_id);
            $find($relation->to_event_id);

            if ($relation->relation === 'simultaneous') {
                $union($relation->from_event_id, $relation->to_event_id);
            }
        }

        $classes = [];

        foreach (array_keys($parent) as $id) {
            $classes[$id] = $find($id);
        }

        $before = [];

        foreach ($relations as $relation) {
            if ($relation->relation !== 'before') {
                continue;
            }

            $from = $find($relation->from_event_id);
            $to = $find($relation->to_event_id);

            if ($from !== $to) {
                $before[$from][$to] = $to;
            }
        }

        return [$classes, $before];
    }

    private function reachable(array $graph, int $from, int $to): bool
    {
        $seen = [];
        $stack = [$from];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node === $to) {
                return true;
            }

            if (isset($seen[$node])) {
                continue;
            }

            $seen[$node] = true;

            foreach (($graph[$node] ?? []) as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }
}
