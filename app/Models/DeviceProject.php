<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceProject extends Model
{
    protected $fillable = ['device_id', 'novel_id', 'manifest'];

    protected function casts(): array
    {
        return ['manifest' => 'array'];
    }
}
