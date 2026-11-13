<?php

namespace App\Http\Controllers;

use App\Models\BookProfile;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\ExportManifest;
use App\Models\ImageMap;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Snapshot;
use App\Models\Universe;
use App\Services\AccountStorage;
use App\Services\CreativeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class MediaAssetController extends Controller
{
    private const IMAGE_LIMIT = 10 * 1024 * 1024;

    // 10 MP keeps the measured GD decode footprint near 90 MB; recheck after G04 measures cPanel.
    private const IMAGE_MAX_PIXELS = 10_000_000;

    private const ATTACHMENT_LIMIT = 20 * 1024 * 1024;

    public function index(Request $request)
    {
        return response()->json([
            'assets' => MediaAsset::where('owner_id', $request->user()->id)
                ->orderByDesc('id')->get(),
        ]);
    }

    public function documents(Request $request, Universe $universe)
    {
        abort_unless((int) $universe->owner_id === (int) $request->user()->id, 404);

        $novels = $universe->novels()
            ->where('owner_id', $request->user()->id)
            ->orderBy('id')
            ->get(['id', 'title']);
        $novelIds = $novels->pluck('id')->all();

        $assets = MediaAsset::where('owner_id', $request->user()->id)
            ->where('kind', 'attachment')
            ->where(function ($query) use ($universe, $novelIds) {
                $query->where('universe_id', $universe->id);

                if ($novelIds !== []) {
                    $query->orWhereIn('novel_id', $novelIds);
                }
            })
            ->orderByDesc('id')
            ->get();

        $previews = [];

        foreach ($assets as $asset) {
            if ($asset->mime === 'text/plain' && Storage::disk('local')->exists($asset->path)) {
                $previews[$asset->id] = Str::limit(Storage::disk('local')->get($asset->path), 5000);
            }
        }

        return view('workspace.documents', [
            'universe' => $universe,
            'novels' => $novels,
            'assets' => $assets,
            'previews' => $previews,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:image,attachment'],
            'file' => ['required', 'file'],
            'universe_id' => ['nullable', 'integer'],
            'novel_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $novel = null;

        if (! empty($data['novel_id'])) {
            $novel = Novel::whereKey($data['novel_id'])->where('owner_id', $user->id)->firstOrFail();
        }

        if (! empty($data['universe_id'])) {
            Universe::whereKey($data['universe_id'])->where('owner_id', $user->id)->firstOrFail();
        }

        if ($novel && ! empty($data['universe_id']) && $novel->universe_id !== (int) $data['universe_id']) {
            throw ValidationException::withMessages([
                'universe_id' => 'La novela no pertenece a ese universo.',
            ]);
        }

        $file = $data['file'];
        $limit = $data['kind'] === 'image' ? self::IMAGE_LIMIT : self::ATTACHMENT_LIMIT;
        $bytes = (int) $file->getSize();

        if ($bytes < 1 || $bytes > $limit) {
            throw ValidationException::withMessages([
                'file' => $data['kind'] === 'image'
                    ? 'La imagen debe pesar como máximo 10 MiB.'
                    : 'El adjunto debe pesar como máximo 20 MiB.',
            ]);
        }

        $inspected = $data['kind'] === 'image'
            ? $this->inspectImage($file->getRealPath())
            : $this->inspectAttachment($file->getRealPath());

        app(AccountStorage::class)->assertCanStore($user->id, $bytes);

        $stored = $file->storeAs(
            'media/'.$user->id,
            Str::uuid().'.'.$inspected['extension'],
            'local',
        );

        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo.']);
        }

        $asset = MediaAsset::create([
            'owner_id' => $user->id,
            'universe_id' => $data['universe_id'] ?? $novel?->universe_id,
            'novel_id' => $novel?->id,
            'kind' => $data['kind'],
            'original_name' => basename($file->getClientOriginalName()),
            'mime' => $inspected['mime'],
            'bytes' => $bytes,
            'path' => $stored,
            'metadata' => $inspected['metadata'] + ($data['metadata'] ?? []),
        ]);

        app(AccountStorage::class)->sync($user->id);

        if ($this->wantsHtml($request)) {
            return redirect($this->documentsPath($asset->universe_id))
                ->with('status', 'Documento añadido.');
        }

        return response()->json(['asset' => $asset], 201);
    }

    public function download(Request $request, MediaAsset $asset)
    {
        $this->ownAsset($request, $asset);
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return Storage::disk('local')->download($asset->path, $asset->original_name);
    }

    public function view(Request $request, MediaAsset $asset)
    {
        abort_unless($asset->kind === 'image', 404);
        abort_unless(in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);
        abort_unless($this->canViewAsset($request, $asset), 404);
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return Storage::disk('local')->response($asset->path, $asset->original_name, [
            'Content-Type' => $asset->mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, MediaAsset $asset)
    {
        $this->ownAsset($request, $asset);
        $returnUniverse = $asset->universe_id;

        $dependencies = $this->dependencies($asset);

        if ($dependencies !== []) {
            $message = 'El recurso está en uso; no se elimina para no romper un borrador.';

            if ($this->wantsHtml($request)) {
                return redirect($this->documentsPath($returnUniverse))->withErrors(['asset' => $message]);
            }

            return response()->json([
                'message' => $message,
                'dependencies' => $dependencies,
            ], 422);
        }

        Storage::disk('local')->delete($asset->path);
        $asset->delete();
        app(AccountStorage::class)->sync($request->user()->id);

        if ($this->wantsHtml($request)) {
            return redirect($this->documentsPath($returnUniverse))->with('status', 'Documento eliminado.');
        }

        return response()->json(['status' => 'deleted']);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function documentsPath(?int $universeId): string
    {
        return $universeId ? "/workspace/universes/{$universeId}/documents" : '/workspace';
    }

    private function inspectImage(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 32);

        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
            $extension = 'png';
        } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
            $extension = 'jpg';
        } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
            $extension = 'webp';
        } else {
            throw ValidationException::withMessages([
                'file' => 'Solo se admiten imágenes JPEG, PNG o WebP reales.',
            ]);
        }

        $size = @getimagesize($path);
        $width = $size[0] ?? 0;
        $height = $size[1] ?? 0;

        // Bound decoded memory before GD allocates width * height * 4 bytes.
        if ($size === false
            || $width < 1
            || $height < 1
            || $width > self::IMAGE_MAX_PIXELS
            || $height > self::IMAGE_MAX_PIXELS
            || $width * $height > self::IMAGE_MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => 'La imagen no es válida o supera el límite de píxeles.']);
        }

        if (! extension_loaded('gd')) {
            throw ValidationException::withMessages(['file' => 'La validación de imágenes no está disponible en este servidor.']);
        }

        $bytes = (string) file_get_contents($path);
        $this->assertCompleteImage($mime, $bytes);
        $decoded = @imagecreatefromstring($bytes);

        if ($decoded === false) {
            throw ValidationException::withMessages(['file' => 'La imagen está dañada o incompleta.']);
        }

        $decodedWidth = imagesx($decoded);
        $decodedHeight = imagesy($decoded);
        imagedestroy($decoded);

        if ($decodedWidth !== $width || $decodedHeight !== $height) {
            throw ValidationException::withMessages(['file' => 'La imagen no es válida.']);
        }

        return [
            'mime' => $mime,
            'extension' => $extension,
            'metadata' => ['format' => $extension, 'width' => $size[0], 'height' => $size[1]],
        ];
    }

    private function assertCompleteImage(string $mime, string $bytes): void
    {
        $trimmed = rtrim($bytes, "\0 \t\r\n");

        $complete = match ($mime) {
            'image/jpeg' => str_ends_with($trimmed, "\xFF\xD9"),
            'image/png' => str_ends_with($trimmed, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82"),
            'image/webp' => strlen($bytes) >= 12
                && unpack('V', substr($bytes, 4, 4))[1] === strlen($bytes) - 8,
            default => false,
        };

        if (! $complete) {
            throw ValidationException::withMessages(['file' => 'La imagen está incompleta o dañada.']);
        }
    }

    private function inspectAttachment(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);

        if (str_starts_with($head, 'MZ')
            || str_starts_with($head, "\x7FELF")
            || str_starts_with($head, '#!')
            || str_starts_with($head, "\xCA\xFE\xBA\xBE")) {
            throw ValidationException::withMessages([
                'file' => 'No se admiten ejecutables ni contenido activo.',
            ]);
        }

        if (str_starts_with($head, '%PDF-')) {
            $content = (string) file_get_contents($path);

            if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b/', $content)) {
                throw ValidationException::withMessages([
                    'file' => 'El PDF contiene contenido activo no permitido.',
                ]);
            }

            return ['mime' => 'application/pdf', 'extension' => 'pdf', 'metadata' => ['format' => 'pdf']];
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->inspectDocx($path);
        }

        $content = (string) file_get_contents($path);

        if (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) {
            return ['mime' => 'text/plain', 'extension' => 'txt', 'metadata' => ['format' => 'txt']];
        }

        throw ValidationException::withMessages([
            'file' => 'Solo se admiten adjuntos PDF, DOCX o TXT reales.',
        ]);
    }

    private function inspectDocx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'El DOCX no es un paquete válido.']);
        }

        $names = [];
        $uncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
            $uncompressed += (int) ($zip->statIndex($i)['size'] ?? 0);

            if ($uncompressed > 100 * 1024 * 1024) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => 'El DOCX supera el tamaño descomprimido permitido.',
                ]);
            }

            if (str_ends_with(strtolower($name), 'vbaproject.bin')
                || str_contains(strtolower($name), 'activex/')) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => 'El DOCX contiene macros o contenido activo.',
                ]);
            }
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml') ?: '';
        $hasDocument = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        if (! $hasDocument || str_contains(strtolower($contentTypes), 'macroenabled')) {
            throw ValidationException::withMessages(['file' => 'El documento no es un DOCX admitido.']);
        }

        return [
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'metadata' => ['format' => 'docx', 'entries' => count($names)],
        ];
    }

    private function dependencies(MediaAsset $asset): array
    {
        $dependencies = [];

        $maps = ImageMap::where('image_asset_id', $asset->id)->pluck('id')->all();

        if ($maps !== []) {
            $dependencies[] = ['type' => 'image_maps', 'ids' => $maps];
        }

        $usedByScenes = [];

        foreach ($this->ownerScenes($asset->owner_id) as $scene) {
            if (! is_array($scene->content)) {
                continue;
            }
            $count = 0;
            $this->walkAssetRefs($scene->content, $asset->id, $count);
            if ($count > 0) {
                $usedByScenes[] = $scene->id;
            }
        }

        if ($usedByScenes !== []) {
            $dependencies[] = ['type' => 'scenes', 'ids' => $usedByScenes];
        }

        $novelIds = Novel::where('owner_id', $asset->owner_id)->pluck('id')->all();

        if ($novelIds !== []) {
            $snapshots = [];

            foreach (Snapshot::whereIn('novel_id', $novelIds)->get(['id', 'manifest']) as $snapshot) {
                if ($this->manifestUsesAsset($snapshot->manifest ?? [], (int) $asset->id)) {
                    $snapshots[] = $snapshot->id;
                }
            }

            if ($snapshots !== []) {
                $dependencies[] = ['type' => 'snapshots', 'ids' => $snapshots];
            }

            $manifests = [];

            foreach (ExportManifest::whereIn('novel_id', $novelIds)->get(['id', 'manifest']) as $manifest) {
                if ($this->manifestUsesAsset($manifest->manifest ?? [], (int) $asset->id)) {
                    $manifests[] = $manifest->id;
                }
            }

            if ($manifests !== []) {
                $dependencies[] = ['type' => 'export_manifests', 'ids' => $manifests];
            }

            $profiles = [];

            foreach (BookProfile::whereIn('novel_id', $novelIds)->get(['id', 'cover']) as $profile) {
                if ($this->assetRefIn($profile->cover ?? [], (int) $asset->id)) {
                    $profiles[] = $profile->id;
                }
            }

            if ($profiles !== []) {
                $dependencies[] = ['type' => 'book_profiles', 'ids' => $profiles];
            }
        }

        return $dependencies;
    }

    private function manifestUsesAsset(array $manifest, int $assetId): bool
    {
        foreach (($manifest['resources']['media'] ?? []) as $media) {
            if (is_array($media) && (int) ($media['id'] ?? 0) === $assetId) {
                return true;
            }
        }

        return $this->assetRefIn($manifest, $assetId);
    }

    private function assetRefIn(mixed $value, int $assetId): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)
                && (str_contains(strtolower($key), 'asset') || str_contains(strtolower($key), 'image'))
                && is_numeric($item)
                && (int) $item === $assetId) {
                return true;
            }

            if (is_array($item) && $this->assetRefIn($item, $assetId)) {
                return true;
            }
        }

        return false;
    }

    private function ownerScenes(int $ownerId)
    {
        $novelIds = Novel::where('owner_id', $ownerId)->pluck('id');
        $chapterIds = Chapter::whereIn('novel_id', $novelIds)->pluck('id');
        $alternativeIds = ChapterAlternative::whereIn('chapter_id', $chapterIds)->pluck('id');

        return Scene::whereIn('chapter_alternative_id', $alternativeIds)->get();
    }

    private function walkAssetRefs(array &$content, int $assetId, int &$count): void
    {
        foreach ($content as $key => &$value) {
            $isAssetKey = is_string($key)
                && (str_contains(strtolower($key), 'asset') || str_contains(strtolower($key), 'image'));

            if ($isAssetKey) {
                if ($value === $assetId) {
                    $count++;
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item === $assetId) {
                            $count++;
                        }
                    }
                }
            }

            if (is_array($value)) {
                $this->walkAssetRefs($value, $assetId, $count);
            }
        }
        unset($value);
    }

    private function canViewAsset(Request $request, MediaAsset $asset): bool
    {
        if ((int) $asset->owner_id === (int) $request->user()->id) {
            return true;
        }

        if (! $asset->novel_id) {
            return false;
        }

        $novel = Novel::find($asset->novel_id);

        if (! $novel) {
            return false;
        }

        $user = $request->user();

        if (CreativeAccess::allows($user, 'novel', (int) $novel->id, 'read')) {
            return true;
        }

        $chapterIds = CreativeAccess::accessibleChapterIds($user, $novel);
        $alternativeIds = ChapterAlternative::whereIn('chapter_id', $chapterIds)->pluck('id');

        foreach (Scene::whereIn('chapter_alternative_id', $alternativeIds)->cursor() as $scene) {
            if (is_array($scene->content) && $this->containsAsset($scene->content, (int) $asset->id)) {
                return true;
            }
        }

        return false;
    }

    private function containsAsset(array $content, int $assetId): bool
    {
        foreach ($content as $key => $value) {
            $isAssetKey = is_string($key)
                && (str_contains(strtolower($key), 'asset') || str_contains(strtolower($key), 'image'));

            if ($isAssetKey && $value === $assetId) {
                return true;
            }

            if (is_array($value) && $this->containsAsset($value, $assetId)) {
                return true;
            }
        }

        return false;
    }

    private function ownAsset(Request $request, MediaAsset $asset): void
    {
        abort_unless($asset->owner_id === $request->user()->id, 404);
    }
}
