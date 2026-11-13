<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Universe extends Model
{
    protected $fillable = ['owner_id', 'name', 'description'];

    public function novels()
    {
        return $this->hasMany(Novel::class);
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }
}
