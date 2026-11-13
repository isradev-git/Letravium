<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TextReference extends Model
{
    protected $fillable = ['scene_id', 'card_id', 'created_by'];
}
