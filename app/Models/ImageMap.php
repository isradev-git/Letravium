<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageMap extends Model
{
    protected $fillable = ['universe_id', 'novel_id', 'image_asset_id', 'markers'];

    protected function casts(): array
    {
        return ['markers' => 'array'];
    }

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'image_asset_id');
    }
}
