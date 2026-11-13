<?php

namespace App\Http\Controllers;

use App\Models\Universe;
use App\Services\UniverseBackup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    public function index(Request $request)
    {
        return view('workspace.backups', [
            'universes' => Universe::where('owner_id', $request->user()->id)
                ->withCount('novels')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function export(Request $request, UniverseBackup $backup)
    {
        $data = $request->validate(['universe_id' => ['required', 'integer']]);

        $universe = Universe::whereKey($data['universe_id'])
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $path = $backup->export($universe, $request->user());

        return response()
            ->download($path, 'universo-'.$universe->id.'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    public function restore(Request $request, UniverseBackup $backup)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'name' => ['nullable', 'string', 'max:160'],
        ]);

        $path = $data['file']->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if (! str_starts_with($head, "PK\x03\x04")) {
            throw ValidationException::withMessages(['file' => 'La copia no es un paquete ZIP válido.']);
        }

        $universe = $backup->restore($path, $request->user(), [
            'name' => $data['name'] ?? null,
        ]);

        if ($request->query('format') === 'html') {
            return redirect('/workspace')->with('status', 'Universo restaurado como copia privada.');
        }

        return response()->json(['universe' => $universe], 201);
    }
}
