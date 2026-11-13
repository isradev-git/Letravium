<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['user_id', 'name', 'authorized_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['authorized_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function projects()
    {
        return $this->hasMany(DeviceProject::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
