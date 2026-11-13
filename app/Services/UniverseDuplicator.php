<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardRelationship;
use App\Models\Universe;
use Illuminate\Support\Facades\DB;

class UniverseDuplicator
{
    public function duplicate(Universe $universe, int $ownerId): Universe
    {
        return DB::transaction(function () use ($universe, $ownerId) {
            $copy = Universe::create([
                'owner_id' => $ownerId,
                'name' => $universe->name.' (copia)',
                'description' => $universe->description,
            ]);

            $cardMap = [];
            foreach ($universe->cards as $card) {
                $newCard = Card::create([
                    'universe_id' => $copy->id,
                    'area' => $card->area,
                    'name' => $card->name,
                    'template' => $card->template,
                    'fields' => $card->fields,
                    'text' => $card->text,
                ]);
                $cardMap[$card->id] = $newCard->id;
            }

            foreach (CardRelationship::where('universe_id', $universe->id)->get() as $relationship) {
                CardRelationship::create([
                    'universe_id' => $copy->id,
                    'source_card_id' => $cardMap[$relationship->source_card_id],
                    'target_card_id' => $cardMap[$relationship->target_card_id],
                    'label' => $relationship->label,
                    'directed' => $relationship->directed,
                ]);
            }

            return $copy;
        });
    }
}
