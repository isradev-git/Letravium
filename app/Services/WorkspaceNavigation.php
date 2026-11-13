<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Models\Universe;
use Illuminate\Http\Request;

class WorkspaceNavigation
{
    public function context(?Request $request = null): array
    {
        $request ??= request();
        $route = $request->route();
        $user = $request->user();

        $novel = $this->model($route?->parameter('novel'), Novel::class);

        if ($novel === null) {
            $snapshot = $this->model($route?->parameter('snapshot'), Snapshot::class);
            $novel = $snapshot?->novel;
        }

        $universe = $novel?->universe
            ?? $this->model($route?->parameter('universe'), Universe::class);

        if ($universe === null) {
            $card = $this->model($route?->parameter('card'), Card::class);
            $universe = $card?->universe;
        }

        $owner = true;
        $canRead = false;

        if ($novel !== null && $user !== null) {
            $owner = (int) $novel->owner_id === (int) $user->id;
            $canRead = $owner || CreativeAccess::canReadProject($user, $novel);

            if (! $canRead) {
                $novel = null;
                $universe = null;
            }
        } elseif ($universe !== null && $user !== null) {
            $owner = (int) $universe->owner_id === (int) $user->id;

            if (! $owner) {
                $universe = null;
            }
        }

        return [
            'novel' => $novel,
            'universe' => $universe,
            'owner' => $owner,
            'canRead' => $canRead,
            'active' => $this->active($request),
        ];
    }

    private function active(Request $request): string
    {
        $path = trim($request->path(), '/');

        return match (true) {
            $path === 'workspace' => 'library',
            str_starts_with($path, 'workspace/universes/') => 'universe',
            str_starts_with($path, 'workspace/cards/') => 'universe',
            str_contains($path, '/planning') => 'planning',
            preg_match('#^workspace/novels/\d+$#', $path) === 1 => 'writing',
            str_contains($path, '/comments') => 'review',
            str_starts_with($path, 'workspace/snapshots/'),
            str_contains($path, '/snapshots') => 'versions',
            str_contains($path, '/book') => 'book',
            str_starts_with($path, 'workspace/imports') => 'imports',
            str_starts_with($path, 'workspace/backups') => 'backups',
            str_starts_with($path, 'workspace/fonts') => 'fonts',
            str_starts_with($path, 'workspace/trash') => 'trash',
            $path === 'account/sessions' => 'account-sessions',
            $path === 'account/marketing' => 'account-marketing',
            $path === 'account/close' => 'account-close',
            str_starts_with($path, 'account/') => 'account',
            default => '',
        };
    }

    private function model(mixed $value, string $class): ?object
    {
        if ($value instanceof $class) {
            return $value;
        }

        return is_numeric($value) ? $class::find((int) $value) : null;
    }
}
