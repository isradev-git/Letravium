<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventRelation extends Model
{
    protected $fillable = ['universe_id', 'from_event_id', 'to_event_id', 'relation'];
}
