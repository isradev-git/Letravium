<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcceptedWord extends Model
{
    protected $fillable = ['universe_id', 'word', 'created_by'];
}
