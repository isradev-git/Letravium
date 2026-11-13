<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\CreativePermission;
use App\Models\Novel;
use App\Models\Universe;
use App\Models\User;

class CreativeAccess
{
    public static function ownerId(string $type, int $id): ?int
    {
        $owner = match ($type) {
            'novel' => Novel::whereKey($id)->value('owner_id'),
            'chapter' => Novel::whereKey(Chapter::whereKey($id)->value('novel_id'))->value('owner_id'),
            'card' => Universe::whereKey(Card::whereKey($id)->value('universe_id'))->value('owner_id'),
            default => null,
        };

        return $owner === null ? null : (int) $owner;
    }

    public static function allows(User $user, string $type, int $id, string $scope): bool
    {
        $owner = self::ownerId($type, $id);

        if ($owner === null) {
            return false;
        }

        if ($owner === (int) $user->id) {
            return true;
        }

        if (self::hasGrant($user, $type, $id, $scope)) {
            return true;
        }

        if ($scope === 'read' && self::hasAnyGrant($user, $type, $id)) {
            return true;
        }

        if ($type === 'chapter') {
            $novelId = (int) Chapter::whereKey($id)->value('novel_id');

            if ($novelId > 0) {
                if (self::hasGrant($user, 'novel', $novelId, $scope)) {
                    return true;
                }

                if ($scope === 'read' && self::hasAnyGrant($user, 'novel', $novelId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    public static function accessibleChapterIds(User $user, Novel $novel): array
    {
        if (self::allows($user, 'novel', $novel->id, 'read')) {
            return Chapter::where('novel_id', $novel->id)->orderBy('position')->pluck('id')->all();
        }

        $granted = CreativePermission::where('user_id', $user->id)
            ->where('resource_type', 'chapter')
            ->pluck('resource_id')
            ->all();

        return Chapter::where('novel_id', $novel->id)
            ->whereIn('id', $granted)
            ->orderBy('position')
            ->pluck('id')
            ->all();
    }

    public static function canReadProject(User $user, Novel $novel): bool
    {
        return self::allows($user, 'novel', $novel->id, 'read')
            || self::accessibleChapterIds($user, $novel) !== [];
    }

    private static function hasGrant(User $user, string $type, int $id, string $scope): bool
    {
        return CreativePermission::where('user_id', $user->id)
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->where('scope', $scope)
            ->exists();
    }

    private static function hasAnyGrant(User $user, string $type, int $id): bool
    {
        return CreativePermission::where('user_id', $user->id)
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->exists();
    }
}
