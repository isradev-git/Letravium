<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaceContainment extends Model
{
    protected $fillable = ['universe_id', 'parent_card_id', 'child_card_id'];
}
