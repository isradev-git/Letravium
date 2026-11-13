<?php

namespace App\Http\Controllers;

use App\Models\BookProfile;
use App\Models\ExportManifest;
use App\Models\Font;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Services\BookExporter;
use App\Services\BookPdfInput;
use App\Services\BookPreflight;
use App\Services\BookSnapshot;
use App\Services\ImageMetadataStripper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    private const FORMATS = ['txt', 'docx', 'epub', 'pdf'];

    public function screen(Request $request, Novel $novel, BookPreflight $preflight)
    {
        $this->ownNovel($request, $novel);

        return view('book.export', [
            'novel' => $novel,
            'preflight' => $preflight->run($novel),
            'profiles' => $novel->hasMany(BookProfile::class)->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Novel $novel, BookPreflight $preflight, BookSnapshot $snapshot)
    {
        $this->ownNovel($request, $novel);

        $data = $request->validate([
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['in:txt,docx,epub,pdf'],
            'profile_id' => ['nullable', 'integer'],
            'snapshot_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $profile = null;
        if (! empty($data['profile_id'])) {
            $profile = $novel->hasMany(BookProfile::class)->whereKey($data['profile_id'])->firstOrFail();
        }

        $savedSnapshot = null;

        if (! empty($data['snapshot_id'])) {
            $savedSnapshot = Snapshot::whereKey($data['snapshot_id'])
                ->where('novel_id', $novel->id)
                ->first();

            if (! $savedSnapshot) {
                return response()->json([
                    'message' => 'La versión guardada no existe para esta novela.',
                ], 422);
            }
        }

        $result = $preflight->run($novel);

        if (! $result['can_export']) {
            return response()->json([
                'message' => 'La comprobación previa tiene fallos que impiden exportar.',
                'preflight' => $result,
            ], 422);
        }

        $formats = array_values(array_unique($data['formats']));

        $manifest = ExportManifest::create([
            'novel_id' => $novel->id,
            'snapshot_id' => $savedSnapshot?->id,
            'formats' => $formats,
            'manifest' => $savedSnapshot
                ? $snapshot->buildFromSnapshot($novel, $savedSnapshot, $profile)
                : $snapshot->build($novel, $profile),
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'manifest' => $this->summary($manifest),
            'preflight' => $result,
        ], 201);
    }

    public function show(Request $request, ExportManifest $manifest)
    {
        $this->ownManifest($request, $manifest);

        return response()->json([
            'manifest' => $this->summary($manifest),
            'snapshot' => $this->authorizedManifest($manifest),
        ]);
    }

    public function export(Request $request, ExportManifest $manifest, string $format, BookExporter $exporter)
    {
        $this->ownManifest($request, $manifest);
        $format = strtolower($format);

        abort_unless(in_array($format, self::FORMATS, true), 404);
        abort_unless(in_array($format, $manifest->formats, true), 422);

        $data = $this->authorizedManifest($manifest);
        $warnings = $exporter->warnings($format);

        abort_if($format === 'pdf', 404);

        $bytes = match ($format) {
            'txt' => $exporter->txt($data),
            'docx' => $exporter->docx($data),
            'epub' => $exporter->epub($data),
        };

        $extension = $format;
        $mime = match ($format) {
            'txt' => 'text/plain; charset=UTF-8',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'epub' => 'application/epub+zip',
        };

        $name = (Str::slug((string) ($data['metadata']['title'] ?? 'libro')) ?: 'libro').'.'.$extension;

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Export-Manifest' => (string) $manifest->id,
            'X-Export-Warnings' => implode(' | ', $warnings),
        ]);
    }

    public function pdf(Request $request, ExportManifest $manifest, BookPdfInput $builder, BookExporter $exporter)
    {
        $this->ownManifest($request, $manifest);
        abort_unless(in_array('pdf', $manifest->formats, true), 422);

        $data = $this->authorizedManifest($manifest);
        $payload = $builder->build($data, $manifest->id);

        return view('export.pdf', [
            'title' => (string) ($data['metadata']['title'] ?? 'Libro'),
            'input' => $payload['input'],
            'resources' => [
                'fonts' => $payload['fonts'],
                'media' => $payload['media'],
                'filename' => $payload['filename'],
            ],
            'warnings' => $exporter->warnings('pdf'),
        ]);
    }

    public function pdfResource(Request $request, ExportManifest $manifest, string $kind, int $id, ImageMetadataStripper $images)
    {
        $novel = $this->ownManifest($request, $manifest);
        abort_unless(in_array($kind, ['font', 'media'], true), 404);

        $collection = $kind === 'font' ? 'fonts' : 'media';
        $resource = collect($this->authorizedManifest($manifest)['resources'][$collection] ?? [])
            ->first(fn (array $item) => (int) ($item['id'] ?? 0) === $id);

        abort_unless($resource !== null && ! empty($resource['exists']), 404);

        $path = (string) ($resource['path'] ?? '');
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        $owned = $kind === 'font'
            ? Font::whereKey($id)->where('owner_id', $novel->owner_id)->where('path', $path)->exists()
            : MediaAsset::whereKey($id)->where('owner_id', $novel->owner_id)->where('path', $path)->exists();
        abort_unless($owned, 404);

        $mime = $kind === 'font'
            ? (str_ends_with(strtolower($path), '.otf') ? 'font/otf' : 'font/ttf')
            : (string) ($resource['mime'] ?? 'application/octet-stream');

        if ($kind === 'media') {
            $prepared = $images->prepare(Storage::disk('local')->get($path), $mime);

            return response($prepared['bytes'], 200, [
                'Content-Type' => $prepared['mime'],
                'Content-Disposition' => 'attachment; filename="'.$id.'.'.($prepared['extension'] ?? 'bin').'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.basename($path).'"',
        ]);
    }

    private function summary(ExportManifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'novel_id' => $manifest->novel_id,
            'snapshot_id' => $manifest->snapshot_id,
            'formats' => $manifest->formats,
            'created_by' => $manifest->created_by,
            'created_at' => $manifest->created_at?->toISOString(),
        ];
    }

    private function authorizedManifest(ExportManifest $manifest): array
    {
        $novel = Novel::findOrFail($manifest->novel_id);
        $data = $manifest->manifest;
        $data['resources'] ??= [];
        $data['resources']['fonts'] ??= [];
        $data['resources']['media'] ??= [];

        $fontIds = array_map(fn (array $font): int => (int) ($font['id'] ?? 0), $data['resources']['fonts']);
        $mediaIds = array_map(fn (array $asset): int => (int) ($asset['id'] ?? 0), $data['resources']['media']);

        $ownedFonts = $fontIds === [] ? [] : Font::where('owner_id', $novel->owner_id)
            ->whereIn('id', $fontIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ownedMedia = $mediaIds === [] ? [] : MediaAsset::where('owner_id', $novel->owner_id)
            ->whereIn('id', $mediaIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $data['resources']['fonts'] = array_values(array_filter(
            $data['resources']['fonts'],
            fn (array $font): bool => in_array((int) ($font['id'] ?? 0), $ownedFonts, true),
        ));
        $data['resources']['media'] = array_values(array_filter(
            $data['resources']['media'],
            fn (array $asset): bool => in_array((int) ($asset['id'] ?? 0), $ownedMedia, true),
        ));

        return $data;
    }

    private function ownManifest(Request $request, ExportManifest $manifest): Novel
    {
        $novel = Novel::findOrFail($manifest->novel_id);
        $this->ownNovel($request, $novel);

        return $novel;
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }
}
