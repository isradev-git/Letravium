<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnapshotItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['snapshot_id', 'resource_type', 'resource_id', 'revision', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'revision' => 'integer'];
    }

    public function snapshot()
    {
        return $this->belongsTo(Snapshot::class);
    }
}
