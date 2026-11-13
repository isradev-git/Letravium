<?php

namespace App\Services;

use App\Models\AccountQuota;
use App\Models\Font;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountStorage
{
    public function used(int $ownerId): int
    {
        $stored = (int) MediaAsset::where('owner_id', $ownerId)->sum('bytes')
            + (int) Font::where('owner_id', $ownerId)->sum('bytes');

        $staged = 0;
        $disk = Storage::disk('local');

        foreach ($disk->allFiles("imports/{$ownerId}") as $file) {
            $staged += (int) $disk->size($file);
        }

        return $stored + $staged;
    }

    // shortcut: check-then-store is not row-locked; concurrent uploads can exceed
    // the quota by one in-flight file. Lock the quota row if strict enforcement is required.
    public function assertCanStore(int $ownerId, int $bytes): void
    {
        $limit = (int) $this->quota($ownerId)->bytes_limit;

        if ($this->used($ownerId) + $bytes > $limit) {
            throw ValidationException::withMessages([
                'file' => 'La cuenta alcanzó su cuota de almacenamiento.',
            ]);
        }
    }

    public function sync(int $ownerId): int
    {
        $used = $this->used($ownerId);
        $quota = $this->quota($ownerId);
        $quota->forceFill(['bytes_used' => $used])->save();

        return $used;
    }

    private function quota(int $ownerId): AccountQuota
    {
        return AccountQuota::firstOrCreate(
            ['user_id' => $ownerId],
            ['bytes_limit' => AccountQuota::DEFAULT_BYTES],
        );
    }
}
