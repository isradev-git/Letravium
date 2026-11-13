<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Style extends Model
{
    protected $fillable = ['novel_id', 'name', 'kind', 'definition'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }
}
