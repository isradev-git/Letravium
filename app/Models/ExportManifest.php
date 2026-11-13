<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportManifest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'novel_id', 'snapshot_id', 'formats', 'manifest', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'manifest' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
