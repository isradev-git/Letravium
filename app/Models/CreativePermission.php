<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreativePermission extends Model
{
    public const RESOURCE_TYPES = ['novel', 'chapter', 'card'];

    public const SCOPES = ['read', 'comment', 'edit', 'accept_corrections', 'download', 'delete'];

    protected $fillable = [
        'user_id', 'resource_type', 'resource_id', 'scope', 'granted_by',
    ];
}
