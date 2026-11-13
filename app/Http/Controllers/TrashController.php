<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrashController extends Controller
{
    public const TYPES = ['chapter', 'card', 'novel'];

    public function index(Request $request)
    {
        $entries = DB::table('trash_entries')
            ->where('owner_id', $request->user()->id)
            ->orderByDesc('deleted_at')
            ->get()
            ->map(function ($entry) {
                $resource = $this->resource($entry->resource_type, (int) $entry->resource_id);

                return [
                    'id' => $entry->id,
                    'resource_type' => $entry->resource_type,
                    'resource_id' => $entry->resource_id,
                    'resource_name' => $resource ? $this->displayName($resource) : null,
                    'deleted_at' => $entry->deleted_at,
                    'restore_deadline' => $entry->restore_deadline,
                    'expired' => Carbon::parse($entry->restore_deadline)->isPast(),
                    'resource_exists' => $resource !== null,
                ];
            })
            ->all();

        if ($request->expectsJson()) {
            return response()->json(['trash' => $entries]);
        }

        return view('workspace.trash', ['entries' => $entries]);
    }

    public function trash(Request $request, string $type, int $id)
    {
        $type = strtolower($type);

        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de recurso no admitido.']);
        }

        $this->ownedResource($request, $type, $id, orFail: true);

        $now = now();

        DB::table('trash_entries')->updateOrInsert(
            ['resource_type' => $type, 'resource_id' => $id],
            [
                'owner_id' => $request->user()->id,
                'deleted_at' => $now,
                'restore_deadline' => $now->copy()->addDays(30),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $entry = DB::table('trash_entries')
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->first();

        if (! $request->expectsJson()) {
            return redirect('/workspace/trash')->with('status', 'Elemento movido a la papelera.');
        }

        return response()->json(['trash_entry' => [
            'id' => $entry->id,
            'resource_type' => $entry->resource_type,
            'resource_id' => $entry->resource_id,
            'deleted_at' => $entry->deleted_at,
            'restore_deadline' => $entry->restore_deadline,
        ]], 201);
    }

    public function restore(Request $request, int $entryId)
    {
        $entry = $this->entry($request, $entryId);

        if ($this->resource($entry->resource_type, (int) $entry->resource_id) === null) {
            if (! $request->expectsJson()) {
                return redirect('/workspace/trash')->withErrors([
                    'trash' => 'Falta una dependencia; recupérala antes de restaurar.',
                ]);
            }

            return response()->json([
                'error' => 'missing_dependency',
                'message' => 'Falta una dependencia; recupérala antes de restaurar.',
            ], 409);
        }

        DB::table('trash_entries')->where('id', $entry->id)->delete();

        if (! $request->expectsJson()) {
            return redirect('/workspace/trash')->with('status', 'Elemento restaurado.');
        }

        return response()->json([
            'restored' => true,
            'resource_type' => $entry->resource_type,
            'resource_id' => $entry->resource_id,
        ]);
    }

    public function dependencies(Request $request, int $entryId): JsonResponse
    {
        $entry = $this->entry($request, $entryId);
        $dependencies = $this->dependencyCounts($entry->resource_type, (int) $entry->resource_id);

        return response()->json([
            'dependencies' => $dependencies,
            'can_delete' => array_sum($dependencies) === 0,
        ]);
    }

    public function destroy(Request $request, int $entryId)
    {
        $entry = $this->entry($request, $entryId);
        $data = $request->validate(['confirmation' => ['required', 'string']]);

        $resource = $this->resource($entry->resource_type, (int) $entry->resource_id);

        if ($resource === null) {
            DB::table('trash_entries')->where('id', $entry->id)->delete();

            if (! $request->expectsJson()) {
                return redirect('/workspace/trash')->with('status', 'El elemento ya no existía.');
            }

            return response()->json(['deleted' => true, 'already_missing' => true]);
        }

        if ($data['confirmation'] !== $this->displayName($resource)) {
            throw ValidationException::withMessages([
                'confirmation' => 'Escribe el nombre exacto para confirmar el borrado definitivo.',
            ]);
        }

        $dependencies = $this->dependencyCounts($entry->resource_type, (int) $entry->resource_id);

        if (array_sum($dependencies) > 0) {
            if (! $request->expectsJson()) {
                return redirect('/workspace/trash')->withErrors([
                    'trash' => 'Retira las versiones o referencias que conservan este elemento antes de borrarlo.',
                ]);
            }

            return response()->json([
                'error' => 'dependencies',
                'message' => 'Retira las versiones o referencias que conservan este elemento antes de borrarlo.',
                'dependencies' => $dependencies,
            ], 409);
        }

        DB::table($resource->getTable())->where('id', $resource->getKey())->delete();
        DB::table('trash_entries')->where('id', $entry->id)->delete();

        if (! $request->expectsJson()) {
            return redirect('/workspace/trash')->with('status', 'Elemento eliminado definitivamente.');
        }

        return response()->json([
            'deleted' => true,
            'resource_type' => $entry->resource_type,
            'resource_id' => $entry->resource_id,
        ]);
    }

    private function entry(Request $request, int $entryId): object
    {
        $entry = DB::table('trash_entries')
            ->where('id', $entryId)
            ->where('owner_id', $request->user()->id)
            ->first();

        abort_unless($entry, 404);

        return $entry;
    }

    private function ownedResource(Request $request, string $type, int $id, bool $orFail = false)
    {
        $resource = $this->resource($type, $id);

        if ($resource === null) {
            abort_if($orFail, 404);

            return null;
        }

        $ownerId = match ($type) {
            'novel' => $resource->owner_id,
            'chapter' => Novel::findOrFail($resource->novel_id)->owner_id,
            'card' => Universe::findOrFail($resource->universe_id)->owner_id,
        };

        abort_unless($ownerId === $request->user()->id, 404);

        return $resource;
    }

    private function resource(string $type, int $id)
    {
        return match ($type) {
            'novel' => Novel::withoutGlobalScope('trashed')->find($id),
            'chapter' => Chapter::withoutGlobalScope('trashed')->find($id),
            'card' => Card::withoutGlobalScope('trashed')->find($id),
            default => null,
        };
    }

    private function displayName(object $resource): string
    {
        return match (true) {
            $resource instanceof Novel => $resource->title,
            $resource instanceof Card => $resource->name,
            $resource instanceof Chapter => $resource->title ?? 'Capítulo '.$resource->id,
            default => (string) $resource->id,
        };
    }

    private function dependencyCounts(string $type, int $id): array
    {
        return match ($type) {
            'card' => [
                'text_references' => DB::table('text_references')->where('card_id', $id)->count(),
                'card_relationships' => DB::table('card_relationships')
                    ->where('source_card_id', $id)
                    ->orWhere('target_card_id', $id)
                    ->count(),
            ],
            'novel' => [
                'collections' => DB::table('collection_novel')->where('novel_id', $id)->count(),
            ],
            'chapter' => [],
            default => [],
        };
    }
}
