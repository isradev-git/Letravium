<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Font extends Model
{
    protected $fillable = ['owner_id', 'family', 'variant', 'path', 'bytes', 'license', 'metadata'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'metadata' => 'array'];
    }
}
