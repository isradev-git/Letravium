<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterRole extends Model
{
    protected $fillable = ['novel_id', 'card_id', 'role', 'goals', 'arc'];

    public function card()
    {
        return $this->belongsTo(Card::class);
    }
}
