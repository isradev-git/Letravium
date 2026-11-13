<?php

namespace App\Http\Controllers;

use App\Models\Novel;
use App\Models\Universe;
use App\Services\DocxImport;
use App\Services\ImportCommitter;
use App\Services\ImportStore;
use App\Services\TxtImport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function create(Request $request)
    {
        $universes = Universe::where('owner_id', $request->user()->id)
            ->with(['novels' => fn ($query) => $query
                ->where('owner_id', $request->user()->id)
                ->whereNull('archived_at')
                ->orderBy('id')])
            ->orderBy('id')
            ->get();

        return view('workspace.imports', ['universes' => $universes]);
    }

    public function preview(
        Request $request,
        TxtImport $txtImport,
        DocxImport $docxImport,
        ImportStore $store,
    ) {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'destination' => ['nullable', 'array'],
        ]);

        $destination = array_filter(
            $data['destination'] ?? [],
            fn ($value) => $value !== null && $value !== '',
        );

        if (isset($destination['novel_id'])) {
            abort_unless(
                Novel::whereKey($destination['novel_id'])
                    ->where('owner_id', $request->user()->id)
                    ->exists(),
                404,
            );
        }

        if (isset($destination['universe_id'])) {
            abort_unless(
                Universe::whereKey($destination['universe_id'])
                    ->where('owner_id', $request->user()->id)
                    ->exists(),
                404,
            );
        }

        $file = $data['file'];
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);

        if (str_starts_with($head, "PK\x03\x04")) {
            $format = 'docx';
            $preview = $docxImport->preview($file->getRealPath(), $file->getClientOriginalName());
        } else {
            $format = 'txt';
            $preview = $txtImport->preview($file->getRealPath(), $file->getClientOriginalName());
        }

        $preview['destination'] = $destination === [] ? null : $destination;
        $token = $store->stage($request->user(), $format, $file->getRealPath(), $preview);
        $preview['token'] = $token;

        if (! $this->wantsHtml($request)) {
            return response()->json($preview);
        }

        return $this->previewView($request, $preview, $token);
    }

    public function showPreview(Request $request, ImportStore $store, string $token)
    {
        return $this->previewView($request, $store->load($request->user(), $token), $token);
    }

    public function commit(Request $request, ImportStore $store, ImportCommitter $committer)
    {
        try {
            $data = $request->validate([
                'token' => ['required', 'string'],
                'mode' => ['required', 'in:new_novel,append'],
                'universe_id' => ['required_if:mode,new_novel', 'integer'],
                'novel_id' => ['required_if:mode,append', 'integer'],
                'title' => ['required_if:mode,new_novel', 'nullable', 'string', 'max:200'],
                'chapters' => ['required', 'array', 'min:1'],
                'chapters.*.title' => ['nullable', 'string', 'max:200'],
                'chapters.*.text' => ['required', 'string'],
            ]);
        } catch (ValidationException $exception) {
            if (! $this->wantsHtml($request)) {
                throw $exception;
            }

            $token = (string) $request->input('token');

            if (preg_match('/^[0-9a-f-]{36}$/i', $token) === 1) {
                return redirect('/workspace/imports/preview/'.$token)
                    ->withErrors($exception->errors())
                    ->withInput();
            }

            return redirect('/workspace/imports')->withErrors($exception->errors());
        }

        $store->load($request->user(), $data['token']);
        $novel = $committer->commit($request->user(), $data);
        $store->delete($request->user(), $data['token']);

        if (! $this->wantsHtml($request)) {
            return response()->json(['novel' => $novel->load('chapters')], 201);
        }

        return redirect('/workspace/novels/'.$novel->id)->with('status', 'Importación completada.');
    }

    private function previewView(Request $request, array $preview, string $token)
    {
        return view('workspace.import-preview', [
            'preview' => $preview,
            'token' => $token,
            'destination' => $preview['destination'] ?? null,
            'suggestedTitle' => $request->old('title', pathinfo($preview['source']['name'], PATHINFO_FILENAME) ?: 'Manuscrito'),
        ]);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }
}
