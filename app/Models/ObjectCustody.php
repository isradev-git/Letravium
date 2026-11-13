<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObjectCustody extends Model
{
    protected $fillable = ['universe_id', 'card_id', 'holder_card_id', 'event_id', 'moment'];
}
