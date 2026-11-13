<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportStore
{
    public function stage(User $user, string $extension, string $sourcePath, array $preview): string
    {
        $this->cleanup($user);
        $source = (string) file_get_contents($sourcePath);
        $previewJson = (string) json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        app(AccountStorage::class)->assertCanStore($user->id, strlen($source) + strlen($previewJson));

        $token = (string) Str::uuid();
        $base = $this->base($user, $token);

        Storage::disk('local')->put("{$base}/source.{$extension}", $source);
        Storage::disk('local')->put("{$base}/preview.json", $previewJson);

        app(AccountStorage::class)->sync($user->id);

        return $token;
    }

    public function load(User $user, string $token): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $token) === 1, 404);

        $path = $this->base($user, $token).'/preview.json';
        abort_unless(Storage::disk('local')->exists($path), 404);

        $preview = json_decode((string) Storage::disk('local')->get($path), true);
        abort_unless(is_array($preview), 404);

        return $preview;
    }

    public function delete(User $user, string $token): void
    {
        Storage::disk('local')->deleteDirectory($this->base($user, $token));
        app(AccountStorage::class)->sync($user->id);
    }

    private function cleanup(User $user): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->timestamp;

        foreach ($disk->directories("imports/{$user->id}") as $directory) {
            $latest = 0;

            foreach ($disk->allFiles($directory) as $file) {
                $latest = max($latest, $disk->lastModified($file));
            }

            if ($latest === 0 || $latest < $cutoff) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    private function base(User $user, string $token): string
    {
        return "imports/{$user->id}/{$token}";
    }
}
