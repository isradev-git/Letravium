<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VocabularyGrant extends Model
{
    protected $fillable = ['universe_id', 'novel_id', 'user_id', 'granted_by'];
}
