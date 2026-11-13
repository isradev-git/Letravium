<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditLease extends Model
{
    protected $fillable = ['resource_type', 'resource_id', 'user_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function blocksUser(string $type, int $id, int $userId): bool
    {
        $lease = static::where('resource_type', $type)
            ->where('resource_id', $id)
            ->first();

        return $lease !== null
            && (int) $lease->user_id !== $userId
            && $lease->expires_at?->isFuture() === true;
    }
}
