<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Snapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['novel_id', 'name', 'manifest', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (Snapshot $snapshot) {
            $content = array_diff(array_keys($snapshot->getDirty()), ['name']);

            if ($content !== []) {
                throw new LogicException('Snapshot content is immutable.');
            }
        });
    }

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }

    public function items()
    {
        return $this->hasMany(SnapshotItem::class);
    }

    public function betaReaders()
    {
        return $this->hasMany(BetaReader::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
