<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceOrigin extends Model
{
    protected $fillable = ['universe_id', 'card_id', 'origin', 'place_card_id'];
}
