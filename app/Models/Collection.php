<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = ['owner_id', 'name'];

    public function novels()
    {
        return $this->belongsToMany(Novel::class)->withPivot('position')->orderBy('collection_novel.position');
    }
}
