<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    protected $fillable = [
        'owner_id', 'universe_id', 'novel_id', 'kind',
        'original_name', 'mime', 'bytes', 'path', 'metadata',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'metadata' => 'array'];
    }

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }
}
