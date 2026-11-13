<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarConversion extends Model
{
    protected $fillable = ['universe_id', 'from_calendar_id', 'to_calendar_id', 'from_label', 'to_label'];
}
