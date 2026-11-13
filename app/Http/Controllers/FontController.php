<?php

namespace App\Http\Controllers;

use App\Models\BookProfile;
use App\Models\Font;
use App\Models\Novel;
use App\Models\Snapshot;
use App\Models\Style;
use App\Services\AccountStorage;
use App\Services\CreativeAccess;
use App\Services\FontInspector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FontController extends Controller
{
    private const LIMIT = 10 * 1024 * 1024;

    public function index(Request $request, FontInspector $inspector)
    {
        $fonts = Font::where('owner_id', $request->user()->id)
            ->orderBy('family')->orderBy('variant')->get();

        if ($request->expectsJson()) {
            return response()->json(['fonts' => $fonts]);
        }

        $fontSample = 'El veloz murciélago hindú comía feliz cardillo y kiwi. ¿Qué? ¡Ajá! «España» — 0123456789';
        $fontChecks = [];

        foreach ($fonts as $font) {
            $check = ['status' => 'unavailable', 'missing' => []];

            if (Storage::disk('local')->exists($font->path)) {
                $info = $inspector->inspect(Storage::disk('local')->path($font->path));

                if ($info !== null) {
                    $missing = $inspector->missing($fontSample, $info['coverage']);
                    $check = [
                        'status' => $missing === [] ? 'complete' : 'partial',
                        'missing' => $missing,
                    ];
                }
            }

            $fontChecks[$font->id] = $check;
        }

        return view('workspace.fonts', [
            'fonts' => $fonts,
            'fontChecks' => $fontChecks,
            'fontSample' => $fontSample,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'family' => ['required', 'string', 'max:160'],
            'variant' => ['required', 'string', 'max:80'],
            'license' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
            'file' => ['required', 'file'],
        ]);

        $file = $data['file'];
        $bytes = (int) $file->getSize();

        if ($bytes < 1 || $bytes > self::LIMIT) {
            throw ValidationException::withMessages([
                'file' => 'La fuente debe pesar como máximo 10 MiB.',
            ]);
        }

        $inspected = $this->inspectFont($file->getRealPath());

        app(AccountStorage::class)->assertCanStore($request->user()->id, $bytes);

        $stored = $file->storeAs(
            'fonts/'.$request->user()->id,
            Str::uuid().'.'.$inspected['extension'],
            'local',
        );

        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar la fuente.']);
        }

        $font = Font::create([
            'owner_id' => $request->user()->id,
            'family' => $data['family'],
            'variant' => $data['variant'],
            'license' => $data['license'] ?? null,
            'bytes' => $bytes,
            'path' => $stored,
            'metadata' => $inspected['metadata'] + ($data['metadata'] ?? []),
        ]);

        app(AccountStorage::class)->sync($request->user()->id);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/fonts')->with('status', 'Fuente importada.');
        }

        return response()->json(['font' => $font], 201);
    }

    public function view(Request $request, Font $font)
    {
        abort_unless($this->canViewFont($request->user(), $font), 404);
        abort_unless(Storage::disk('local')->exists($font->path), 404);

        $mime = str_ends_with(strtolower($font->path), '.otf') ? 'font/otf' : 'font/ttf';

        return Storage::disk('local')->response($font->path, basename($font->path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Font $font)
    {
        abort_unless($font->owner_id === $request->user()->id, 404);

        $dependencies = [];
        $novelIds = Novel::where('owner_id', $request->user()->id)->pluck('id');

        foreach (Style::whereIn('novel_id', $novelIds)->get() as $style) {
            $definition = $style->definition;
            if (! is_array($definition)) {
                continue;
            }
            $count = 0;
            $this->walkFontRefs($definition, $font->id, $count);
            if ($count > 0) {
                $dependencies[] = [
                    'type' => 'styles',
                    'ids' => [$style->id],
                    'names' => [$style->name],
                ];
            }
        }

        foreach (BookProfile::whereIn('novel_id', $novelIds)->get() as $profile) {
            $fonts = is_array($profile->fonts) ? $profile->fonts : [];
            $count = 0;
            $this->walkFontRefs($fonts, $font->id, $count);

            if ($count > 0) {
                $dependencies[] = [
                    'type' => 'book_profiles',
                    'ids' => [$profile->id],
                    'names' => [$profile->name],
                ];
            }
        }

        foreach (Snapshot::whereIn('novel_id', $novelIds)->get() as $snapshot) {
            $fonts = $snapshot->manifest['fonts'] ?? [];
            $used = collect(is_array($fonts) ? $fonts : [])->contains(
                fn ($item): bool => is_array($item) && (int) ($item['id'] ?? 0) === (int) $font->id,
            );

            if ($used) {
                $dependencies[] = [
                    'type' => 'snapshots',
                    'ids' => [$snapshot->id],
                    'names' => [$snapshot->name],
                ];
            }
        }

        if ($dependencies !== []) {
            $message = 'La fuente está en uso; no se elimina para no romper un borrador.';

            if ($this->wantsHtml($request)) {
                $names = collect($dependencies)
                    ->flatMap(fn (array $dependency) => $dependency['names'] ?? [])
                    ->implode(', ');

                return redirect('/workspace/fonts')->withErrors([
                    'font' => $message.($names !== '' ? ' Dependencias: '.$names.'.' : ''),
                ]);
            }

            return response()->json([
                'message' => $message,
                'dependencies' => $dependencies,
            ], 422);
        }

        Storage::disk('local')->delete($font->path);
        $font->delete();
        app(AccountStorage::class)->sync($request->user()->id);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/fonts')->with('status', 'Fuente eliminada.');
        }

        return response()->json(['status' => 'deleted']);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function canViewFont($user, Font $font): bool
    {
        if ((int) $font->owner_id === (int) $user->id) {
            return true;
        }

        $novelIds = Novel::where('owner_id', $font->owner_id)->pluck('id');

        foreach (Style::whereIn('novel_id', $novelIds)->get() as $style) {
            $definition = $style->definition;

            if (! is_array($definition)) {
                continue;
            }

            $count = 0;
            $this->walkFontRefs($definition, (int) $font->id, $count);

            if ($count === 0) {
                continue;
            }

            $novel = Novel::find($style->novel_id);

            if ($novel !== null && CreativeAccess::canReadProject($user, $novel)) {
                return true;
            }
        }

        return false;
    }

    private function inspectFont(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);

        if (str_starts_with($head, 'ttcf') || str_starts_with($head, 'wOFF') || str_starts_with($head, 'wOF2')) {
            throw ValidationException::withMessages([
                'file' => 'No se admiten colecciones ni fuentes web; solo TTF/OTF estáticos.',
            ]);
        }

        if (str_starts_with($head, 'OTTO')) {
            return ['extension' => 'otf', 'metadata' => ['format' => 'otf']];
        }

        if (str_starts_with($head, "\x00\x01\x00\x00")
            || str_starts_with($head, 'true')
            || str_starts_with($head, 'typ1')) {
            return ['extension' => 'ttf', 'metadata' => ['format' => 'ttf']];
        }

        throw ValidationException::withMessages([
            'file' => 'El archivo no es una fuente TTF/OTF real.',
        ]);
    }

    private function walkFontRefs(array &$definition, int $fontId, int &$count): void
    {
        foreach ($definition as $key => &$value) {
            $isFontKey = is_string($key) && str_contains(strtolower($key), 'font');

            if ($isFontKey) {
                if ($value === $fontId) {
                    $count++;
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item === $fontId) {
                            $count++;
                        }
                    }
                }
            }

            if (is_array($value)) {
                $this->walkFontRefs($value, $fontId, $count);
            }
        }
        unset($value);
    }
}
