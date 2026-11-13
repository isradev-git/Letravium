<?php

namespace App\Http\Controllers;

use App\Models\BibliographyEntry;
use App\Models\Card;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\Universe;
use App\Services\ContentDocument;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;

class UniverseNotesController extends Controller
{
    public function personalNotes(Request $request)
    {
        $notes = Note::where('author_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        if ($request->expectsJson()) {
            return response()->json([
                'notes' => $notes->map(fn (Note $note) => $this->transform($note)),
            ]);
        }

        return view('workspace.notes', [
            'notes' => $notes,
            'mode' => 'personal',
            'universe' => null,
            'novel' => null,
        ]);
    }

    public function universeNotes(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $notes = Note::where('universe_id', $universe->id)
            ->where('author_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        if ($request->expectsJson()) {
            return response()->json([
                'notes' => $notes->map(fn (Note $note) => $this->transform($note)),
            ]);
        }

        return view('workspace.notes', [
            'notes' => $notes,
            'mode' => 'universe',
            'universe' => $universe,
            'novel' => null,
        ]);
    }

    public function storeNote(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'html' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'content' => ['nullable', 'array'],
            'private' => ['boolean'],
            'pending' => ['boolean'],
            'links' => ['nullable', 'array'],
            'links.*.type' => ['required_with:links', 'in:card,scene,chapter'],
            'links.*.id' => ['required_with:links', 'integer'],
            'links.*.anchor' => ['nullable', 'string'],
        ]);

        [$content, $body] = $this->content($data);

        $note = Note::create([
            'universe_id' => $universe->id,
            'author_id' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $body,
            'content' => $content,
            'private' => $data['private'] ?? true,
            'pending' => $data['pending'] ?? false,
        ]);

        foreach ($data['links'] ?? [] as $link) {
            NoteLink::create([
                'note_id' => $note->id,
                'linkable_type' => $link['type'],
                'linkable_id' => $link['id'],
                'anchor' => $link['anchor'] ?? null,
            ]);
        }

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/notes')
                ->with('status', 'Nota guardada.');
        }

        return response()->json(['note' => $this->transform($note->refresh())], 201);
    }

    public function updateNote(Request $request, Note $note)
    {
        $this->ownNote($request, $note);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'html' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'content' => ['nullable', 'array'],
            'private' => ['boolean'],
            'pending' => ['boolean'],
        ]);

        if (array_key_exists('html', $data) || array_key_exists('content', $data) || array_key_exists('body', $data)) {
            [$content, $body] = $this->content($data);
            $note->forceFill(['content' => $content, 'body' => $body]);
        }

        if (array_key_exists('title', $data)) {
            $note->title = $data['title'];
        }

        if (array_key_exists('private', $data)) {
            $note->private = (bool) $data['private'];
        }

        if (array_key_exists('pending', $data)) {
            $note->forceFill([
                'pending' => (bool) $data['pending'],
                'resolved_at' => $data['pending'] ? null : now(),
            ]);
        }

        $note->save();

        if ($this->wantsHtml($request)) {
            return redirect($this->notesRedirect($request, $note))
                ->with('status', 'Nota actualizada.');
        }

        return response()->json(['note' => $this->transform($note->refresh())]);
    }

    public function setPending(Request $request, Note $note)
    {
        $this->ownNote($request, $note);
        $data = $request->validate(['pending' => ['required', 'boolean']]);

        $note->forceFill([
            'pending' => (bool) $data['pending'],
            'resolved_at' => $data['pending'] ? null : now(),
        ])->save();

        if ($this->wantsHtml($request)) {
            return redirect($this->notesRedirect($request, $note))
                ->with('status', 'Pendiente actualizado.');
        }

        return response()->json(['note' => $this->transform($note->refresh())]);
    }

    public function novelPendings(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        $chapterIds = Chapter::where('novel_id', $novel->id)->pluck('id');
        $alternativeIds = ChapterAlternative::whereIn('chapter_id', $chapterIds)->pluck('id');
        $sceneIds = Scene::whereIn('chapter_alternative_id', $alternativeIds)->pluck('id');
        $noteIds = NoteLink::where('linkable_type', 'scene')->whereIn('linkable_id', $sceneIds)->pluck('note_id');

        $notes = Note::where('author_id', $request->user()->id)
            ->where('pending', true)
            ->where(fn ($query) => $query->whereIn('id', $noteIds)->orWhere('novel_id', $novel->id))
            ->orderByDesc('id')
            ->get();

        if ($request->expectsJson()) {
            return response()->json([
                'pendings' => $notes->map(fn (Note $note) => $this->transform($note)),
            ]);
        }

        return view('workspace.notes', [
            'notes' => $notes,
            'mode' => 'pendings',
            'universe' => null,
            'novel' => $novel,
        ]);
    }

    public function bibliography(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $entries = BibliographyEntry::where('universe_id', $universe->id)
            ->where('author_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['entries' => $entries]);
        }

        return view('workspace.bibliography', [
            'universe' => $universe,
            'entries' => $entries,
            'novels' => Novel::where('universe_id', $universe->id)
                ->where('owner_id', $request->user()->id)
                ->orderBy('title')
                ->get(),
            'notes' => Note::where('universe_id', $universe->id)
                ->where('author_id', $request->user()->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function storeBibliography(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);
        $data = $request->validate([
            'novel_id' => ['nullable', 'integer'],
            'author' => ['nullable', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:300'],
            'date' => ['nullable', 'string', 'max:100'],
            'publisher' => ['nullable', 'string', 'max:200'],
            'url' => ['nullable', 'url'],
            'observations' => ['nullable', 'string'],
        ]);

        if (isset($data['novel_id'])) {
            $novel = Novel::whereKey($data['novel_id'])
                ->where('universe_id', $universe->id)
                ->where('owner_id', $request->user()->id)
                ->first();
            abort_unless($novel !== null, 422);
        }

        $entry = BibliographyEntry::create($data + [
            'universe_id' => $universe->id,
            'author_id' => $request->user()->id,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect($this->bibliographyRedirect($entry))
                ->with('status', 'Entrada añadida a la bibliografía.');
        }

        return response()->json(['entry' => $entry], 201);
    }

    public function selectBibliography(Request $request, BibliographyEntry $entry)
    {
        $this->ownBibliography($request, $entry);
        $data = $request->validate(['selected' => ['required', 'boolean']]);
        $entry->forceFill(['selected' => (bool) $data['selected']])->save();

        if ($this->wantsHtml($request)) {
            return redirect($this->bibliographyRedirect($entry))
                ->with('status', 'Entrada actualizada.');
        }

        return response()->json(['entry' => $entry->refresh()]);
    }

    public function attachBibliography(Request $request, BibliographyEntry $entry, Note $note)
    {
        $this->ownBibliography($request, $entry);
        $this->ownNote($request, $note);

        $entry->notes()->syncWithoutDetaching([$note->id]);

        if ($this->wantsHtml($request)) {
            return redirect($this->bibliographyRedirect($entry))
                ->with('status', 'Nota adjuntada a la entrada.');
        }

        return response()->json(['entry' => $entry->load('notes')]);
    }

    public function novelBibliography(Request $request, Novel $novel)
    {
        $this->ownNovel($request, $novel);

        return response()->json([
            'entries' => BibliographyEntry::where('novel_id', $novel->id)
                ->where('author_id', $request->user()->id)
                ->where('selected', true)
                ->orderBy('id')
                ->get(),
        ]);
    }

    private function content(array $data): array
    {
        if (isset($data['html'])) {
            $blocks = app(HtmlSanitizer::class)->sanitize($data['html'])['blocks'];

            return [$blocks, app(ContentDocument::class)->text(['blocks' => $blocks])];
        }

        if (isset($data['content'])) {
            return [$data['content'], app(ContentDocument::class)->text($data['content'])];
        }

        return [null, $data['body'] ?? null];
    }

    private function transform(Note $note): array
    {
        $links = NoteLink::where('note_id', $note->id)->get()->map(function (NoteLink $link) {
            $exists = match ($link->linkable_type) {
                'card' => Card::whereKey($link->linkable_id)->exists(),
                'scene' => Scene::whereKey($link->linkable_id)->exists(),
                'chapter' => Chapter::whereKey($link->linkable_id)->exists(),
                default => false,
            };

            return [
                'id' => $link->id,
                'type' => $link->linkable_type,
                'target_id' => $link->linkable_id,
                'anchor' => $link->anchor,
                'status' => $exists ? 'attached' : 'detached',
            ];
        });

        return [
            'id' => $note->id,
            'universe_id' => $note->universe_id,
            'novel_id' => $note->novel_id,
            'title' => $note->title,
            'body' => $note->body,
            'content' => $note->content,
            'private' => $note->private,
            'pending' => $note->pending,
            'resolved_at' => $note->resolved_at?->toIso8601String(),
            'links' => $links,
        ];
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function notesRedirect(Request $request, Note $note): string
    {
        if ($note->universe_id !== null) {
            $universe = Universe::whereKey($note->universe_id)
                ->where('owner_id', $request->user()->id)
                ->first();

            if ($universe !== null) {
                return '/workspace/universes/'.$universe->id.'/notes';
            }
        }

        return '/workspace/notes';
    }

    private function bibliographyRedirect(BibliographyEntry $entry): string
    {
        return '/workspace/universes/'.$entry->universe_id.'/bibliography';
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }

    private function ownNovel(Request $request, Novel $novel): void
    {
        abort_unless($novel->owner_id === $request->user()->id, 404);
    }

    private function ownNote(Request $request, Note $note): void
    {
        abort_unless($note->author_id === $request->user()->id, 404);
    }

    private function ownBibliography(Request $request, BibliographyEntry $entry): void
    {
        abort_unless($entry->author_id === $request->user()->id, 404);
    }
}
