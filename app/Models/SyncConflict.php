<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    protected $fillable = [
        'scene_id', 'resource_type', 'resource_id',
        'server_revision', 'client_revision',
        'server_content', 'client_content', 'status',
    ];

    protected function casts(): array
    {
        return [
            'server_revision' => 'integer',
            'client_revision' => 'integer',
            'server_content' => 'array',
            'client_content' => 'array',
        ];
    }
}
