<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardRelationship;
use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Font;
use App\Models\Footnote;
use App\Models\ImageMap;
use App\Models\MediaAsset;
use App\Models\Novel;
use App\Models\Part;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\Style;
use App\Models\TextReference;
use App\Models\Universe;
use App\Models\User;
use App\Models\WorkState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class UniverseBackup
{
    public const FORMAT = 'escritor-universe-backup';

    public const VERSION = 1;

    public const MAX_ENTRIES = 5000;

    public const MAX_EXPANDED = 500 * 1024 * 1024;

    public function export(Universe $universe, User $user): string
    {
        $novels = Novel::where('universe_id', $universe->id)
            ->where('owner_id', $user->id)->orderBy('id')->get();
        $novelIds = $novels->pluck('id')->all();

        $parts = Part::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $workStates = WorkState::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $chapters = Chapter::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $chapterIds = $chapters->pluck('id')->all();
        $alternatives = ChapterAlternative::whereIn('chapter_id', $chapterIds)->orderBy('id')->get();
        $alternativeIds = $alternatives->pluck('id')->all();
        $scenes = Scene::whereIn('chapter_alternative_id', $alternativeIds)->orderBy('id')->get();
        $sceneIds = $scenes->pluck('id')->all();
        $plots = Plot::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $plotIds = $plots->pluck('id')->all();
        $styles = Style::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $footnotes = Footnote::whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $contentRevisions = DB::table('content_revisions')->whereIn('scene_id', $sceneIds)->orderBy('id')->get()
            ->map(fn ($row): array => [
                'scene_id' => (int) $row->scene_id,
                'revision' => (int) $row->revision,
                'content' => $row->content,
                'reason' => $row->reason,
                'created_at' => $row->created_at,
            ])->all();
        $historyEntries = DB::table('history_entries')->whereIn('novel_id', $novelIds)->orderBy('id')->get()
            ->map(fn ($row): array => [
                'novel_id' => (int) $row->novel_id,
                'resource_type' => $row->resource_type,
                'resource_id' => (int) $row->resource_id,
                'revision' => (int) $row->revision,
                'payload' => $row->payload,
                'reason' => $row->reason,
                'created_at' => $row->created_at,
            ])->all();
        $notes = DB::table('notes')
            ->where('author_id', $user->id)
            ->where(function ($query) use ($universe, $novelIds): void {
                $query->where('universe_id', $universe->id);

                if ($novelIds !== []) {
                    $query->orWhereIn('novel_id', $novelIds);
                }
            })->orderBy('id')->get();
        $noteIds = $notes->pluck('id')->all();
        $noteLinks = $noteIds === []
            ? collect()
            : DB::table('note_links')->whereIn('note_id', $noteIds)->orderBy('id')->get();
        $events = DB::table('events')->where('universe_id', $universe->id)->orderBy('id')->get();
        $eventIds = $events->pluck('id')->all();
        $eventRelations = $eventIds === []
            ? collect()
            : DB::table('event_relations')->whereIn('from_event_id', $eventIds)->orderBy('id')->get();
        $calendars = DB::table('calendars')->where('universe_id', $universe->id)->orderBy('id')->get();
        $calendarIds = $calendars->pluck('id')->all();
        $calendarConversions = $calendarIds === []
            ? collect()
            : DB::table('calendar_conversions')->whereIn('from_calendar_id', $calendarIds)->orderBy('id')->get();
        $storyChanges = DB::table('story_changes')->where('universe_id', $universe->id)->orderBy('id')->get();
        $storyContexts = DB::table('story_contexts')->where('universe_id', $universe->id)->orderBy('id')->get();
        $objectCustodies = DB::table('object_custodies')->where('universe_id', $universe->id)->orderBy('id')->get();
        $resourceOrigins = DB::table('resource_origins')->where('universe_id', $universe->id)->orderBy('id')->get();
        $glossaryTerms = DB::table('glossary_terms')->where('universe_id', $universe->id)->orderBy('id')->get();
        $characterRoles = $novelIds === []
            ? collect()
            : DB::table('character_roles')->whereIn('novel_id', $novelIds)->orderBy('id')->get();
        $placeContainments = DB::table('place_containments')->where('universe_id', $universe->id)->orderBy('id')->get();
        $cardFieldDefinitions = DB::table('card_field_definitions')->where('universe_id', $universe->id)->orderBy('id')->get();
        $snapshots = $novelIds === []
            ? collect()
            : DB::table('snapshots')->whereIn('novel_id', $novelIds)->where('created_by', $user->id)->orderBy('id')->get();
        $snapshotIds = $snapshots->pluck('id')->all();
        $snapshotItems = $snapshotIds === []
            ? collect()
            : DB::table('snapshot_items')->whereIn('snapshot_id', $snapshotIds)->orderBy('id')->get();
        $comments = $novelIds === []
            ? collect()
            : DB::table('comments')->whereIn('novel_id', $novelIds)->where('author_id', $user->id)->orderBy('id')->get();
        $commentIds = $comments->pluck('id')->all();
        $commentMessages = $commentIds === []
            ? collect()
            : DB::table('comment_messages')->whereIn('comment_id', $commentIds)->where('author_id', $user->id)->orderBy('id')->get();

        $plotScenes = DB::table('plot_scene')->whereIn('plot_id', $plotIds)
            ->whereIn('scene_id', $sceneIds)->orderBy('id')->get()
            ->map(fn ($row): array => [
                'plot_id' => (int) $row->plot_id,
                'scene_id' => (int) $row->scene_id,
            ])->all();

        $novelGoals = DB::table('novel_goals')->whereIn('novel_id', $novelIds)->get()
            ->map(fn ($row): array => [
                'novel_id' => (int) $row->novel_id,
                'target_words' => $row->target_words === null ? null : (int) $row->target_words,
            ])->all();

        $cards = Card::where('universe_id', $universe->id)->orderBy('id')->get();
        $cardIds = $cards->pluck('id')->all();
        $relationships = CardRelationship::where('universe_id', $universe->id)->orderBy('id')->get();
        $textReferences = TextReference::whereIn('scene_id', $sceneIds)
            ->whereIn('card_id', $cardIds)->orderBy('id')->get();

        $imageMaps = ImageMap::where(function ($query) use ($universe, $novelIds): void {
            $query->where('universe_id', $universe->id);

            if ($novelIds !== []) {
                $query->orWhereIn('novel_id', $novelIds);
            }
        })->orderBy('id')->get();

        $assets = MediaAsset::where('owner_id', $user->id)
            ->where(function ($query) use ($universe, $novelIds): void {
                $query->where('universe_id', $universe->id);

                if ($novelIds !== []) {
                    $query->orWhereIn('novel_id', $novelIds);
                }
            })->orderBy('id')->get();

        $fontIds = [];

        foreach ($styles as $style) {
            $this->collectFontIds($style->definition, $fontIds);
        }

        $fonts = $fontIds === []
            ? collect()
            : Font::where('owner_id', $user->id)->whereIn('id', $fontIds)->orderBy('id')->get();

        $directory = 'backups/'.$user->id;
        Storage::disk('local')->makeDirectory($directory);
        $path = Storage::disk('local')->path($directory.'/'.Str::uuid().'.zip');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages(['package' => 'No se pudo crear el paquete.']);
        }

        $files = [];

        $add = function (string $name, string $content) use ($zip, &$files): void {
            if ($zip->addFromString($name, $content) === false) {
                throw ValidationException::withMessages(['package' => 'No se pudo escribir el paquete.']);
            }

            $files[$name] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        };

        try {
            $assetRecords = [];

            foreach ($assets as $asset) {
                if (! Storage::disk('local')->exists($asset->path)) {
                    throw ValidationException::withMessages([
                        'package' => "Falta el recurso del archivo «{$asset->original_name}».",
                    ]);
                }

                $content = (string) Storage::disk('local')->get($asset->path);
                $name = 'resources/media/'.$asset->id.'/'.$this->safeName($asset->original_name, 'resource.bin');
                $add($name, $content);

                $record = $asset->only([
                    'id', 'universe_id', 'novel_id', 'kind', 'original_name', 'mime', 'metadata',
                ]);
                $record['bytes'] = strlen($content);
                $record['resource'] = $name;
                $assetRecords[] = $record;
            }

            $fontRecords = [];

            foreach ($fonts as $font) {
                if (! Storage::disk('local')->exists($font->path)) {
                    throw ValidationException::withMessages([
                        'package' => "Falta el recurso de la fuente «{$font->family}».",
                    ]);
                }

                $content = (string) Storage::disk('local')->get($font->path);
                $name = 'resources/fonts/'.$font->id.'/'.$this->safeName(basename($font->path), 'font.bin');
                $add($name, $content);

                $record = $font->only(['id', 'family', 'variant', 'license', 'metadata']);
                $record['bytes'] = strlen($content);
                $record['resource'] = $name;
                $fontRecords[] = $record;
            }

            $content = [
                'universe' => $universe->only(['name', 'description']),
                'cards' => $cards->map(fn (Card $card): array => $card->only([
                    'id', 'area', 'name', 'template', 'fields', 'text',
                ]))->all(),
                'card_relationships' => $relationships->map(fn (CardRelationship $relationship): array => $relationship->only([
                    'id', 'source_card_id', 'target_card_id', 'label', 'directed',
                ]))->all(),
                'novels' => $novels->map(fn (Novel $novel): array => $novel->only([
                    'id', 'title', 'status', 'archived_at', 'settings',
                ]))->all(),
                'parts' => $parts->map(fn (Part $part): array => $part->only([
                    'id', 'novel_id', 'title', 'position',
                ]))->all(),
                'work_states' => $workStates->map(fn (WorkState $state): array => $state->only([
                    'id', 'novel_id', 'name', 'position',
                ]))->all(),
                'chapters' => $chapters->map(fn (Chapter $chapter): array => $chapter->only([
                    'id', 'novel_id', 'part_id', 'title', 'position', 'plan',
                ]))->all(),
                'chapter_alternatives' => $alternatives->map(fn (ChapterAlternative $alternative): array => $alternative->only([
                    'id', 'chapter_id', 'work_state_id', 'alias', 'is_active', 'plan',
                ]))->all(),
                'scenes' => $scenes->map(fn (Scene $scene): array => $scene->only([
                    'id', 'chapter_alternative_id', 'title', 'position', 'summary',
                    'pov_card_id', 'place_card_id', 'narrative_moment', 'plan', 'content',
                ]))->all(),
                'plots' => $plots->map(fn (Plot $plot): array => $plot->only([
                    'id', 'novel_id', 'name', 'description', 'notes',
                ]))->all(),
                'plot_scene' => $plotScenes,
                'styles' => $styles->map(fn (Style $style): array => $style->only([
                    'id', 'novel_id', 'name', 'kind', 'definition',
                ]))->all(),
                'footnotes' => $footnotes->map(fn (Footnote $footnote): array => $footnote->only([
                    'id', 'novel_id', 'scene_id', 'kind', 'number', 'text', 'content',
                ]))->all(),
                'content_revisions' => $contentRevisions,
                'history_entries' => $historyEntries,
                'notes' => $notes->map(fn ($note): array => [
                    'id' => (int) $note->id,
                    'universe_id' => $note->universe_id === null ? null : (int) $note->universe_id,
                    'novel_id' => $note->novel_id === null ? null : (int) $note->novel_id,
                    'title' => $note->title,
                    'body' => $note->body,
                    'content' => $note->content,
                    'private' => (bool) $note->private,
                    'pending' => (bool) $note->pending,
                    'resolved_at' => $note->resolved_at,
                    'created_at' => $note->created_at,
                    'updated_at' => $note->updated_at,
                ])->all(),
                'note_links' => $noteLinks->map(fn ($link): array => [
                    'id' => (int) $link->id,
                    'note_id' => (int) $link->note_id,
                    'linkable_type' => $link->linkable_type,
                    'linkable_id' => (int) $link->linkable_id,
                    'anchor' => $link->anchor,
                    'created_at' => $link->created_at,
                    'updated_at' => $link->updated_at,
                ])->all(),
                'events' => $events->map(fn ($event): array => [
                    'id' => (int) $event->id,
                    'name' => $event->name,
                    'type' => $event->type,
                    'description' => $event->description,
                    'moment' => $event->moment,
                    'participants' => $event->participants,
                    'places' => $event->places,
                    'created_at' => $event->created_at,
                    'updated_at' => $event->updated_at,
                ])->all(),
                'event_relations' => $eventRelations->map(fn ($relation): array => [
                    'id' => (int) $relation->id,
                    'from_event_id' => (int) $relation->from_event_id,
                    'to_event_id' => (int) $relation->to_event_id,
                    'relation' => $relation->relation,
                    'created_at' => $relation->created_at,
                    'updated_at' => $relation->updated_at,
                ])->all(),
                'calendars' => $calendars->map(fn ($calendar): array => [
                    'id' => (int) $calendar->id,
                    'name' => $calendar->name,
                    'config' => $calendar->config,
                    'is_current' => (bool) $calendar->is_current,
                    'superseded_at' => $calendar->superseded_at,
                    'created_at' => $calendar->created_at,
                    'updated_at' => $calendar->updated_at,
                ])->all(),
                'calendar_conversions' => $calendarConversions->map(fn ($conversion): array => [
                    'id' => (int) $conversion->id,
                    'from_calendar_id' => (int) $conversion->from_calendar_id,
                    'to_calendar_id' => (int) $conversion->to_calendar_id,
                    'from_label' => $conversion->from_label,
                    'to_label' => $conversion->to_label,
                    'created_at' => $conversion->created_at,
                    'updated_at' => $conversion->updated_at,
                ])->all(),
                'story_changes' => $storyChanges->map(fn ($change): array => [
                    'id' => (int) $change->id,
                    'card_id' => (int) $change->card_id,
                    'field' => $change->field,
                    'value' => $change->value,
                    'effective_event_id' => $change->effective_event_id === null ? null : (int) $change->effective_event_id,
                    'moment' => $change->moment,
                    'created_at' => $change->created_at,
                    'updated_at' => $change->updated_at,
                ])->all(),
                'story_contexts' => $storyContexts->map(fn ($context): array => [
                    'id' => (int) $context->id,
                    'novel_id' => $context->novel_id === null ? null : (int) $context->novel_id,
                    'scene_id' => $context->scene_id === null ? null : (int) $context->scene_id,
                    'card_id' => $context->card_id === null ? null : (int) $context->card_id,
                    'event_id' => $context->event_id === null ? null : (int) $context->event_id,
                    'context' => $context->context,
                    'captured_at' => $context->captured_at,
                    'created_at' => $context->created_at,
                    'updated_at' => $context->updated_at,
                ])->all(),
                'object_custodies' => $objectCustodies->map(fn ($custody): array => [
                    'id' => (int) $custody->id,
                    'card_id' => (int) $custody->card_id,
                    'holder_card_id' => $custody->holder_card_id === null ? null : (int) $custody->holder_card_id,
                    'event_id' => $custody->event_id === null ? null : (int) $custody->event_id,
                    'moment' => $custody->moment,
                    'created_at' => $custody->created_at,
                    'updated_at' => $custody->updated_at,
                ])->all(),
                'resource_origins' => $resourceOrigins->map(fn ($origin): array => [
                    'id' => (int) $origin->id,
                    'card_id' => (int) $origin->card_id,
                    'origin' => $origin->origin,
                    'place_card_id' => $origin->place_card_id === null ? null : (int) $origin->place_card_id,
                    'created_at' => $origin->created_at,
                    'updated_at' => $origin->updated_at,
                ])->all(),
                'glossary_terms' => $glossaryTerms->map(fn ($term): array => [
                    'id' => (int) $term->id,
                    'language_card_id' => $term->language_card_id === null ? null : (int) $term->language_card_id,
                    'term' => $term->term,
                    'meaning' => $term->meaning,
                    'preferred_form' => $term->preferred_form,
                    'variants' => $term->variants,
                    'examples' => $term->examples,
                    'created_at' => $term->created_at,
                    'updated_at' => $term->updated_at,
                ])->all(),
                'character_roles' => $characterRoles->map(fn ($role): array => [
                    'id' => (int) $role->id,
                    'novel_id' => (int) $role->novel_id,
                    'card_id' => (int) $role->card_id,
                    'role' => $role->role,
                    'goals' => $role->goals,
                    'arc' => $role->arc,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ])->all(),
                'place_containments' => $placeContainments->map(fn ($containment): array => [
                    'id' => (int) $containment->id,
                    'parent_card_id' => (int) $containment->parent_card_id,
                    'child_card_id' => (int) $containment->child_card_id,
                    'created_at' => $containment->created_at,
                    'updated_at' => $containment->updated_at,
                ])->all(),
                'card_field_definitions' => $cardFieldDefinitions->map(fn ($field): array => [
                    'id' => (int) $field->id,
                    'key' => $field->key,
                    'type' => $field->type,
                    'label' => $field->label,
                    'options' => $field->options,
                    'temporal' => (bool) $field->temporal,
                    'removed_at' => $field->removed_at,
                    'removed_values' => $field->removed_values,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at,
                ])->all(),
                'snapshots' => $snapshots->map(fn ($snapshot): array => [
                    'id' => (int) $snapshot->id,
                    'novel_id' => (int) $snapshot->novel_id,
                    'name' => $snapshot->name,
                    'manifest' => $snapshot->manifest,
                    'created_at' => $snapshot->created_at,
                ])->all(),
                'snapshot_items' => $snapshotItems->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'snapshot_id' => (int) $item->snapshot_id,
                    'resource_type' => $item->resource_type,
                    'resource_id' => (int) $item->resource_id,
                    'revision' => (int) $item->revision,
                    'payload' => $item->payload,
                ])->all(),
                'comments' => $comments->map(fn ($comment): array => [
                    'id' => (int) $comment->id,
                    'novel_id' => (int) $comment->novel_id,
                    'chapter_id' => $comment->chapter_id === null ? null : (int) $comment->chapter_id,
                    'scene_id' => $comment->scene_id === null ? null : (int) $comment->scene_id,
                    'snapshot_id' => $comment->snapshot_id === null ? null : (int) $comment->snapshot_id,
                    'block_id' => $comment->block_id,
                    'quoted_text' => $comment->quoted_text,
                    'status' => $comment->status,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                ])->all(),
                'comment_messages' => $commentMessages->map(fn ($message): array => [
                    'id' => (int) $message->id,
                    'comment_id' => (int) $message->comment_id,
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                    'updated_at' => $message->updated_at,
                ])->all(),
                'text_references' => $textReferences->map(fn (TextReference $reference): array => $reference->only([
                    'id', 'scene_id', 'card_id',
                ]))->all(),
                'image_maps' => $imageMaps->map(fn (ImageMap $map): array => $map->only([
                    'id', 'universe_id', 'novel_id', 'image_asset_id', 'markers',
                ]))->all(),
                'media_assets' => $assetRecords,
                'fonts' => $fontRecords,
                'novel_goals' => $novelGoals,
            ];

            $encoded = (string) json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            );
            $add('content.json', $encoded);

            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'created_at' => now()->toIso8601String(),
                'universe' => $universe->only(['name', 'description']),
                'counts' => [
                    'novels' => count($content['novels']),
                    'cards' => count($content['cards']),
                    'scenes' => count($content['scenes']),
                    'resources' => count($assetRecords) + count($fontRecords),
                ],
                'files' => $files,
                'excluded' => ['beta_readers', 'foreign_comments', 'correction_proposals', 'invitations', 'auth_secrets'],
            ];

            $zip->addFromString(
                'manifest.json',
                (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            );
            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    public function restore(string $path, User $user, array $options = []): Universe
    {
        $package = $this->inspectPackage($path);
        $content = $package['content'];
        $resources = $package['resources'];

        $incoming = 0;

        foreach (['media_assets', 'fonts'] as $key) {
            foreach ($content[$key] as $row) {
                $resource = $row['resource'] ?? null;

                if (is_string($resource) && isset($resources[$resource])) {
                    $incoming += strlen($resources[$resource]);
                }
            }
        }

        app(AccountStorage::class)->assertCanStore($user->id, $incoming);

        $imageMimes = [];

        foreach ($content['media_assets'] as $asset) {
            $kind = $asset['kind'] ?? null;
            $bytes = $resources[$asset['resource']];

            if ($kind === 'image') {
                $info = @getimagesizefromstring($bytes);

                if (! is_array($info)
                    || ! in_array($info['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)
                    || (int) ($info[0] ?? 0) < 1
                    || (int) ($info[1] ?? 0) < 1) {
                    throw ValidationException::withMessages([
                        'file' => 'La copia contiene un recurso de imagen no válido.',
                    ]);
                }

                $imageMimes[$asset['id']] = $info['mime'];

                continue;
            }

            if ($kind !== 'attachment') {
                throw ValidationException::withMessages([
                    'file' => 'La copia contiene un recurso de tipo no admitido.',
                ]);
            }
        }

        $assetPaths = [];
        $fontPaths = [];
        $written = [];

        try {
            foreach ($content['media_assets'] as $asset) {
                $bytes = $resources[$asset['resource']];
                $extension = pathinfo($asset['original_name'], PATHINFO_EXTENSION) ?: 'bin';
                $newPath = 'media/'.$user->id.'/'.Str::uuid().'.'.$extension;
                Storage::disk('local')->put($newPath, $bytes);
                $written[] = $newPath;
                $assetPaths[$asset['id']] = $newPath;
            }

            foreach ($content['fonts'] as $font) {
                $bytes = $resources[$font['resource']];
                $extension = pathinfo($font['resource'], PATHINFO_EXTENSION) ?: 'bin';
                $newPath = 'fonts/'.$user->id.'/'.Str::uuid().'.'.$extension;
                Storage::disk('local')->put($newPath, $bytes);
                $written[] = $newPath;
                $fontPaths[$font['id']] = $newPath;
            }

            $universe = DB::transaction(function () use ($content, $user, $options, $assetPaths, $fontPaths, $imageMimes): Universe {
                $fontMap = [];

                foreach ($content['fonts'] as $font) {
                    $newFont = Font::create([
                        'owner_id' => $user->id,
                        'family' => $font['family'],
                        'variant' => $font['variant'],
                        'path' => $fontPaths[$font['id']],
                        'bytes' => (int) $font['bytes'],
                        'license' => $font['license'] ?? null,
                        'metadata' => $font['metadata'] ?? null,
                    ]);
                    $fontMap[$font['id']] = $newFont->id;
                }

                $universe = Universe::create([
                    'owner_id' => $user->id,
                    'name' => $options['name'] ?? ($content['universe']['name'].' (restaurado)'),
                    'description' => $content['universe']['description'] ?? null,
                ]);

                $cardMap = [];

                foreach ($content['cards'] as $card) {
                    $newCard = Card::create([
                        'universe_id' => $universe->id,
                        'area' => $card['area'],
                        'name' => $card['name'],
                        'template' => $card['template'] ?? null,
                        'fields' => $card['fields'] ?? null,
                        'text' => $card['text'] ?? null,
                    ]);
                    $cardMap[$card['id']] = $newCard->id;
                }

                foreach ($content['card_relationships'] as $relationship) {
                    CardRelationship::create([
                        'universe_id' => $universe->id,
                        'source_card_id' => $cardMap[$relationship['source_card_id']],
                        'target_card_id' => $cardMap[$relationship['target_card_id']],
                        'label' => $relationship['label'],
                        'directed' => $relationship['directed'] ?? true,
                    ]);
                }

                $novelMap = [];

                foreach ($content['novels'] as $novel) {
                    $newNovel = Novel::create([
                        'universe_id' => $universe->id,
                        'owner_id' => $user->id,
                        'title' => $novel['title'],
                        'settings' => $novel['settings'] ?? null,
                    ]);

                    $extra = array_filter([
                        'status' => $novel['status'] ?? null,
                        'archived_at' => $novel['archived_at'] ?? null,
                    ], fn ($value) => $value !== null);

                    if ($extra !== []) {
                        $newNovel->forceFill($extra)->save();
                    }

                    $novelMap[$novel['id']] = $newNovel->id;
                }

                $assetMap = [];

                foreach ($content['media_assets'] as $asset) {
                    $newAsset = MediaAsset::create([
                        'owner_id' => $user->id,
                        'universe_id' => $asset['universe_id'] === null ? null : $universe->id,
                        'novel_id' => isset($asset['novel_id']) ? ($novelMap[$asset['novel_id']] ?? null) : null,
                        'kind' => $asset['kind'],
                        'original_name' => $asset['original_name'],
                        'mime' => $imageMimes[$asset['id']] ?? $asset['mime'],
                        'bytes' => (int) $asset['bytes'],
                        'path' => $assetPaths[$asset['id']],
                        'metadata' => $asset['metadata'] ?? null,
                    ]);
                    $assetMap[$asset['id']] = $newAsset->id;
                }

                $partMap = [];

                foreach ($content['parts'] as $part) {
                    $newPart = Part::create([
                        'novel_id' => $novelMap[$part['novel_id']],
                        'title' => $part['title'],
                        'position' => (int) $part['position'],
                    ]);
                    $partMap[$part['id']] = $newPart->id;
                }

                $stateMap = [];

                foreach ($content['work_states'] as $state) {
                    $newState = WorkState::create([
                        'novel_id' => $novelMap[$state['novel_id']],
                        'name' => $state['name'],
                        'position' => (int) $state['position'],
                    ]);
                    $stateMap[$state['id']] = $newState->id;
                }

                $chapterMap = [];

                foreach ($content['chapters'] as $chapter) {
                    $newChapter = Chapter::create([
                        'novel_id' => $novelMap[$chapter['novel_id']],
                        'part_id' => isset($chapter['part_id']) ? ($partMap[$chapter['part_id']] ?? null) : null,
                        'title' => $chapter['title'] ?? null,
                        'position' => (int) $chapter['position'],
                        'plan' => $chapter['plan'] ?? null,
                    ]);
                    $chapterMap[$chapter['id']] = $newChapter->id;
                }

                $alternativeMap = [];

                foreach ($content['chapter_alternatives'] as $alternative) {
                    $newAlternative = ChapterAlternative::create([
                        'chapter_id' => $chapterMap[$alternative['chapter_id']],
                        'work_state_id' => isset($alternative['work_state_id'])
                            ? ($stateMap[$alternative['work_state_id']] ?? null)
                            : null,
                        'alias' => $alternative['alias'],
                        'is_active' => (bool) ($alternative['is_active'] ?? false),
                        'plan' => $alternative['plan'] ?? null,
                    ]);
                    $alternativeMap[$alternative['id']] = $newAlternative->id;
                }

                $styleMap = [];

                foreach ($content['styles'] as $style) {
                    $newStyle = Style::create([
                        'novel_id' => $novelMap[$style['novel_id']],
                        'name' => $style['name'],
                        'kind' => $style['kind'],
                        'definition' => $this->remapFontIds($style['definition'] ?? [], $fontMap),
                    ]);
                    $styleMap[$style['id']] = $newStyle->id;
                }

                $sceneMap = [];

                foreach ($content['scenes'] as $scene) {
                    $newScene = Scene::create([
                        'chapter_alternative_id' => $alternativeMap[$scene['chapter_alternative_id']],
                        'title' => $scene['title'] ?? null,
                        'position' => (int) $scene['position'],
                        'summary' => $scene['summary'] ?? null,
                        'pov_card_id' => isset($scene['pov_card_id']) ? ($cardMap[$scene['pov_card_id']] ?? null) : null,
                        'place_card_id' => isset($scene['place_card_id']) ? ($cardMap[$scene['place_card_id']] ?? null) : null,
                        'narrative_moment' => $scene['narrative_moment'] ?? null,
                        'plan' => $scene['plan'] ?? null,
                        'content' => $scene['content'] ?? null,
                    ]);
                    $sceneMap[$scene['id']] = $newScene->id;
                }

                $plotMap = [];

                foreach ($content['plots'] as $plot) {
                    $newPlot = Plot::create([
                        'novel_id' => $novelMap[$plot['novel_id']],
                        'name' => $plot['name'],
                        'description' => $plot['description'] ?? null,
                        'notes' => $plot['notes'] ?? null,
                    ]);
                    $plotMap[$plot['id']] = $newPlot->id;
                }

                foreach ($content['plot_scene'] as $link) {
                    DB::table('plot_scene')->insertOrIgnore([
                        'plot_id' => $plotMap[$link['plot_id']],
                        'scene_id' => $sceneMap[$link['scene_id']],
                    ]);
                }

                $footnoteMap = [];

                foreach ($content['footnotes'] as $footnote) {
                    $newFootnote = Footnote::create([
                        'novel_id' => $novelMap[$footnote['novel_id']],
                        'scene_id' => isset($footnote['scene_id'])
                            ? ($sceneMap[$footnote['scene_id']] ?? null)
                            : null,
                        'kind' => $footnote['kind'],
                        'number' => (int) $footnote['number'],
                        'text' => $footnote['text'],
                        'content' => $footnote['content'] ?? null,
                    ]);
                    $footnoteMap[$footnote['id']] = $newFootnote->id;
                }

                foreach ($content['scenes'] as $scene) {
                    $body = $scene['content'] ?? null;

                    if (! isset($sceneMap[$scene['id']]) || ! is_array($body)) {
                        continue;
                    }

                    Scene::whereKey($sceneMap[$scene['id']])->update([
                        'content' => $this->remapSceneContent($body, $assetMap, $styleMap, $footnoteMap, $fontMap),
                    ]);
                }

                foreach ($content['content_revisions'] ?? [] as $revision) {
                    $newSceneId = $sceneMap[$revision['scene_id'] ?? 0] ?? null;

                    if ($newSceneId === null) {
                        continue;
                    }

                    DB::table('content_revisions')->insertOrIgnore([
                        'scene_id' => $newSceneId,
                        'revision' => (int) $revision['revision'],
                        'content' => is_array($revision['content'] ?? null)
                            ? json_encode($revision['content'], JSON_UNESCAPED_UNICODE)
                            : ($revision['content'] ?? '{}'),
                        'author_id' => $user->id,
                        'reason' => $revision['reason'] ?? 'save',
                        'created_at' => $revision['created_at'] ?? now(),
                    ]);
                }

                $historyMaps = [
                    'novel' => $novelMap,
                    'part' => $partMap,
                    'work_state' => $stateMap,
                    'chapter' => $chapterMap,
                    'chapter_alternative' => $alternativeMap,
                    'scene' => $sceneMap,
                    'plot' => $plotMap,
                    'style' => $styleMap,
                    'footnote' => $footnoteMap,
                    'card' => $cardMap,
                ];

                foreach ($content['history_entries'] ?? [] as $entry) {
                    $type = (string) ($entry['resource_type'] ?? '');
                    $newResourceId = $historyMaps[$type][$entry['resource_id'] ?? 0] ?? null;

                    if (! isset($historyMaps[$type]) || $newResourceId === null) {
                        continue;
                    }

                    DB::table('history_entries')->insertOrIgnore([
                        'novel_id' => $novelMap[$entry['novel_id'] ?? 0] ?? null,
                        'resource_type' => $type,
                        'resource_id' => $newResourceId,
                        'revision' => (int) ($entry['revision'] ?? 0),
                        'payload' => is_array($entry['payload'] ?? null)
                            ? json_encode($entry['payload'], JSON_UNESCAPED_UNICODE)
                            : ($entry['payload'] ?? '{}'),
                        'reason' => $entry['reason'] ?? 'restore',
                        'created_by' => $user->id,
                        'created_at' => $entry['created_at'] ?? now(),
                    ]);
                }

                $calendarMap = [];

                foreach ($content['calendars'] ?? [] as $calendar) {
                    $newCalendarId = DB::table('calendars')->insertGetId([
                        'universe_id' => $universe->id,
                        'name' => $calendar['name'],
                        'config' => is_array($calendar['config'] ?? null)
                            ? json_encode($calendar['config'], JSON_UNESCAPED_UNICODE)
                            : ($calendar['config'] ?? null),
                        'is_current' => (bool) ($calendar['is_current'] ?? true),
                        'superseded_at' => $calendar['superseded_at'] ?? null,
                        'created_at' => $calendar['created_at'] ?? now(),
                        'updated_at' => $calendar['updated_at'] ?? now(),
                    ]);
                    $calendarMap[$calendar['id']] = $newCalendarId;
                }

                foreach ($content['calendar_conversions'] ?? [] as $conversion) {
                    $from = $calendarMap[$conversion['from_calendar_id']] ?? null;
                    $to = $calendarMap[$conversion['to_calendar_id']] ?? null;

                    if ($from === null || $to === null) {
                        continue;
                    }

                    DB::table('calendar_conversions')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'from_calendar_id' => $from,
                        'to_calendar_id' => $to,
                        'from_label' => $conversion['from_label'],
                        'to_label' => $conversion['to_label'],
                        'created_at' => $conversion['created_at'] ?? now(),
                        'updated_at' => $conversion['updated_at'] ?? now(),
                    ]);
                }

                $eventMap = [];

                foreach ($content['events'] ?? [] as $event) {
                    $newEventId = DB::table('events')->insertGetId([
                        'universe_id' => $universe->id,
                        'name' => $event['name'],
                        'type' => $event['type'] ?? 'event',
                        'description' => $event['description'] ?? null,
                        'moment' => $event['moment'] ?? null,
                        'participants' => $this->remapJsonIds($event['participants'] ?? null, $cardMap),
                        'places' => $this->remapJsonIds($event['places'] ?? null, $cardMap),
                        'created_at' => $event['created_at'] ?? now(),
                        'updated_at' => $event['updated_at'] ?? now(),
                    ]);
                    $eventMap[$event['id']] = $newEventId;
                }

                foreach ($content['event_relations'] ?? [] as $relation) {
                    $from = $eventMap[$relation['from_event_id']] ?? null;
                    $to = $eventMap[$relation['to_event_id']] ?? null;

                    if ($from === null || $to === null) {
                        continue;
                    }

                    DB::table('event_relations')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'from_event_id' => $from,
                        'to_event_id' => $to,
                        'relation' => $relation['relation'],
                        'created_at' => $relation['created_at'] ?? now(),
                        'updated_at' => $relation['updated_at'] ?? now(),
                    ]);
                }

                $noteMap = [];

                foreach ($content['notes'] ?? [] as $note) {
                    $newNoteId = DB::table('notes')->insertGetId([
                        'universe_id' => $universe->id,
                        'novel_id' => isset($note['novel_id']) ? ($novelMap[$note['novel_id']] ?? null) : null,
                        'author_id' => $user->id,
                        'title' => $note['title'] ?? null,
                        'body' => $note['body'] ?? null,
                        'content' => is_array($note['content'] ?? null)
                            ? json_encode($note['content'], JSON_UNESCAPED_UNICODE)
                            : ($note['content'] ?? null),
                        'private' => (bool) ($note['private'] ?? true),
                        'pending' => (bool) ($note['pending'] ?? false),
                        'resolved_at' => $note['resolved_at'] ?? null,
                        'created_at' => $note['created_at'] ?? now(),
                        'updated_at' => $note['updated_at'] ?? now(),
                    ]);
                    $noteMap[$note['id']] = $newNoteId;
                }

                $linkMaps = ['card' => $cardMap, 'scene' => $sceneMap, 'chapter' => $chapterMap];

                foreach ($content['note_links'] ?? [] as $link) {
                    $newNoteId = $noteMap[$link['note_id']] ?? null;
                    $targetMap = $linkMaps[$link['linkable_type'] ?? ''] ?? null;
                    $newTargetId = $targetMap[$link['linkable_id']] ?? null;

                    if ($newNoteId === null || $newTargetId === null) {
                        continue;
                    }

                    DB::table('note_links')->insertOrIgnore([
                        'note_id' => $newNoteId,
                        'linkable_type' => $link['linkable_type'],
                        'linkable_id' => $newTargetId,
                        'anchor' => $link['anchor'] ?? null,
                        'created_at' => $link['created_at'] ?? now(),
                        'updated_at' => $link['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['story_changes'] ?? [] as $change) {
                    $cardId = $cardMap[$change['card_id']] ?? null;

                    if ($cardId === null) {
                        continue;
                    }

                    DB::table('story_changes')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'card_id' => $cardId,
                        'field' => $change['field'],
                        'value' => $this->remapJsonIds($change['value'] ?? null, $cardMap),
                        'effective_event_id' => isset($change['effective_event_id'])
                            ? ($eventMap[$change['effective_event_id']] ?? null)
                            : null,
                        'moment' => $change['moment'] ?? null,
                        'created_by' => $user->id,
                        'created_at' => $change['created_at'] ?? now(),
                        'updated_at' => $change['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['story_contexts'] ?? [] as $context) {
                    DB::table('story_contexts')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'novel_id' => isset($context['novel_id']) ? ($novelMap[$context['novel_id']] ?? null) : null,
                        'scene_id' => isset($context['scene_id']) ? ($sceneMap[$context['scene_id']] ?? null) : null,
                        'card_id' => isset($context['card_id']) ? ($cardMap[$context['card_id']] ?? null) : null,
                        'event_id' => isset($context['event_id']) ? ($eventMap[$context['event_id']] ?? null) : null,
                        'context' => $this->remapJsonIds($context['context'] ?? null, $cardMap),
                        'captured_at' => $context['captured_at'] ?? now(),
                        'created_at' => $context['created_at'] ?? now(),
                        'updated_at' => $context['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['object_custodies'] ?? [] as $custody) {
                    $cardId = $cardMap[$custody['card_id']] ?? null;

                    if ($cardId === null) {
                        continue;
                    }

                    DB::table('object_custodies')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'card_id' => $cardId,
                        'holder_card_id' => isset($custody['holder_card_id'])
                            ? ($cardMap[$custody['holder_card_id']] ?? null)
                            : null,
                        'event_id' => isset($custody['event_id']) ? ($eventMap[$custody['event_id']] ?? null) : null,
                        'moment' => $custody['moment'] ?? null,
                        'created_at' => $custody['created_at'] ?? now(),
                        'updated_at' => $custody['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['resource_origins'] ?? [] as $origin) {
                    $cardId = $cardMap[$origin['card_id']] ?? null;

                    if ($cardId === null) {
                        continue;
                    }

                    DB::table('resource_origins')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'card_id' => $cardId,
                        'origin' => $origin['origin'] ?? null,
                        'place_card_id' => isset($origin['place_card_id'])
                            ? ($cardMap[$origin['place_card_id']] ?? null)
                            : null,
                        'created_at' => $origin['created_at'] ?? now(),
                        'updated_at' => $origin['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['glossary_terms'] ?? [] as $term) {
                    DB::table('glossary_terms')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'language_card_id' => isset($term['language_card_id'])
                            ? ($cardMap[$term['language_card_id']] ?? null)
                            : null,
                        'term' => $term['term'],
                        'meaning' => $term['meaning'] ?? null,
                        'preferred_form' => $term['preferred_form'] ?? null,
                        'variants' => is_array($term['variants'] ?? null)
                            ? json_encode($term['variants'], JSON_UNESCAPED_UNICODE)
                            : ($term['variants'] ?? null),
                        'examples' => is_array($term['examples'] ?? null)
                            ? json_encode($term['examples'], JSON_UNESCAPED_UNICODE)
                            : ($term['examples'] ?? null),
                        'created_at' => $term['created_at'] ?? now(),
                        'updated_at' => $term['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['character_roles'] ?? [] as $role) {
                    $novelId = $novelMap[$role['novel_id']] ?? null;
                    $cardId = $cardMap[$role['card_id']] ?? null;

                    if ($novelId === null || $cardId === null) {
                        continue;
                    }

                    DB::table('character_roles')->insertOrIgnore([
                        'novel_id' => $novelId,
                        'card_id' => $cardId,
                        'role' => $role['role'] ?? null,
                        'goals' => $role['goals'] ?? null,
                        'arc' => $role['arc'] ?? null,
                        'created_at' => $role['created_at'] ?? now(),
                        'updated_at' => $role['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['place_containments'] ?? [] as $containment) {
                    $parentId = $cardMap[$containment['parent_card_id']] ?? null;
                    $childId = $cardMap[$containment['child_card_id']] ?? null;

                    if ($parentId === null || $childId === null) {
                        continue;
                    }

                    DB::table('place_containments')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'parent_card_id' => $parentId,
                        'child_card_id' => $childId,
                        'created_at' => $containment['created_at'] ?? now(),
                        'updated_at' => $containment['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['card_field_definitions'] ?? [] as $field) {
                    DB::table('card_field_definitions')->insertOrIgnore([
                        'universe_id' => $universe->id,
                        'key' => $field['key'],
                        'type' => $field['type'],
                        'label' => $field['label'],
                        'options' => is_array($field['options'] ?? null)
                            ? json_encode($field['options'], JSON_UNESCAPED_UNICODE)
                            : ($field['options'] ?? null),
                        'temporal' => (bool) ($field['temporal'] ?? false),
                        'removed_at' => $field['removed_at'] ?? null,
                        'removed_values' => is_array($field['removed_values'] ?? null)
                            ? json_encode($field['removed_values'], JSON_UNESCAPED_UNICODE)
                            : ($field['removed_values'] ?? null),
                        'created_at' => $field['created_at'] ?? now(),
                        'updated_at' => $field['updated_at'] ?? now(),
                    ]);
                }

                $resourceMaps = [
                    'novel' => $novelMap,
                    'part' => $partMap,
                    'work_state' => $stateMap,
                    'chapter' => $chapterMap,
                    'chapter_alternative' => $alternativeMap,
                    'scene' => $sceneMap,
                    'style' => $styleMap,
                    'font' => $fontMap,
                ];
                $snapshotMap = [];

                foreach ($content['snapshots'] ?? [] as $snapshot) {
                    $manifest = is_array($snapshot['manifest'] ?? null)
                        ? $snapshot['manifest']
                        : json_decode((string) ($snapshot['manifest'] ?? '{}'), true);
                    $manifest = is_array($manifest)
                        ? $this->remapSnapshotManifest($manifest, $novelMap, $partMap, $stateMap, $chapterMap, $alternativeMap, $sceneMap, $styleMap, $fontMap, $footnoteMap, $assetMap)
                        : [];

                    $newSnapshotId = DB::table('snapshots')->insertGetId([
                        'novel_id' => $novelMap[$snapshot['novel_id']] ?? null,
                        'name' => $snapshot['name'],
                        'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                        'created_by' => $user->id,
                        'created_at' => $snapshot['created_at'] ?? now(),
                    ]);
                    $snapshotMap[$snapshot['id']] = $newSnapshotId;
                }

                foreach ($content['snapshot_items'] ?? [] as $item) {
                    $newSnapshotId = $snapshotMap[$item['snapshot_id']] ?? null;
                    $type = (string) ($item['resource_type'] ?? '');
                    $newResourceId = $resourceMaps[$type][$item['resource_id']] ?? null;

                    if ($newSnapshotId === null || $newResourceId === null) {
                        continue;
                    }

                    $payload = is_array($item['payload'] ?? null)
                        ? $item['payload']
                        : json_decode((string) ($item['payload'] ?? '{}'), true);
                    $payload = is_array($payload)
                        ? $this->remapSnapshotPayload($type, $payload, $novelMap, $partMap, $stateMap, $chapterMap, $alternativeMap, $sceneMap, $styleMap, $cardMap, $footnoteMap, $fontMap, $assetMap)
                        : [];

                    DB::table('snapshot_items')->insertOrIgnore([
                        'snapshot_id' => $newSnapshotId,
                        'resource_type' => $type,
                        'resource_id' => $newResourceId,
                        'revision' => (int) ($item['revision'] ?? 0),
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);
                }

                $commentMap = [];

                foreach ($content['comments'] ?? [] as $comment) {
                    $newChapterId = $comment['chapter_id'] === null ? null : ($chapterMap[$comment['chapter_id']] ?? null);
                    $newSceneId = $comment['scene_id'] === null ? null : ($sceneMap[$comment['scene_id']] ?? null);
                    $newSnapshotId = $comment['snapshot_id'] === null ? null : ($snapshotMap[$comment['snapshot_id']] ?? null);

                    if (($comment['chapter_id'] !== null && $newChapterId === null)
                        || ($comment['scene_id'] !== null && $newSceneId === null)
                        || ($comment['snapshot_id'] !== null && $newSnapshotId === null)) {
                        continue;
                    }

                    $newCommentId = DB::table('comments')->insertGetId([
                        'novel_id' => $novelMap[$comment['novel_id']] ?? null,
                        'chapter_id' => $newChapterId,
                        'scene_id' => $newSceneId,
                        'snapshot_id' => $newSnapshotId,
                        'block_id' => $comment['block_id'] ?? null,
                        'quoted_text' => $comment['quoted_text'] ?? null,
                        'status' => $comment['status'] ?? 'open',
                        'author_id' => $user->id,
                        'created_at' => $comment['created_at'] ?? now(),
                        'updated_at' => $comment['updated_at'] ?? now(),
                    ]);
                    $commentMap[$comment['id']] = $newCommentId;
                }

                foreach ($content['comment_messages'] ?? [] as $message) {
                    $newCommentId = $commentMap[$message['comment_id']] ?? null;

                    if ($newCommentId === null) {
                        continue;
                    }

                    DB::table('comment_messages')->insertOrIgnore([
                        'comment_id' => $newCommentId,
                        'author_id' => $user->id,
                        'body' => $message['body'],
                        'created_at' => $message['created_at'] ?? now(),
                        'updated_at' => $message['updated_at'] ?? now(),
                    ]);
                }

                foreach ($content['text_references'] as $reference) {
                    DB::table('text_references')->insertOrIgnore([
                        'scene_id' => $sceneMap[$reference['scene_id']],
                        'card_id' => $cardMap[$reference['card_id']],
                        'created_by' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($content['image_maps'] as $map) {
                    $markers = $map['markers'] ?? null;

                    if (is_array($markers)) {
                        foreach ($markers as &$marker) {
                            if (is_array($marker) && isset($marker['place_card_id'])) {
                                $marker['place_card_id'] = $cardMap[$marker['place_card_id']] ?? $marker['place_card_id'];
                            }
                        }
                        unset($marker);
                    }

                    ImageMap::create([
                        'universe_id' => $map['universe_id'] === null ? null : $universe->id,
                        'novel_id' => isset($map['novel_id']) ? ($novelMap[$map['novel_id']] ?? null) : null,
                        'image_asset_id' => $assetMap[$map['image_asset_id']],
                        'markers' => $markers,
                    ]);
                }

                foreach ($content['novel_goals'] as $goal) {
                    DB::table('novel_goals')->insertOrIgnore([
                        'novel_id' => $novelMap[$goal['novel_id']],
                        'target_words' => $goal['target_words'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $universe;
            });
        } catch (\Throwable $exception) {
            foreach ($written as $file) {
                Storage::disk('local')->delete($file);
            }

            throw $exception;
        }

        app(AccountStorage::class)->sync($user->id);

        return $universe;
    }

    private function inspectPackage(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'La copia no es un ZIP válido.']);
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw ValidationException::withMessages(['file' => 'La copia contiene demasiados archivos.']);
        }

        $expanded = 0;
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafeName($name);
            $names[] = $name;
            $expanded += (int) ($zip->statIndex($i)['size'] ?? 0);

            if ($expanded > self::MAX_EXPANDED) {
                $zip->close();

                throw ValidationException::withMessages(['file' => 'La copia supera el tamaño expandido permitido.']);
            }
        }

        $manifestRaw = $zip->getFromName('manifest.json');

        if ($manifestRaw === false) {
            $zip->close();

            throw ValidationException::withMessages(['file' => 'La copia no contiene un manifiesto.']);
        }

        $manifest = json_decode($manifestRaw, true);

        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== self::FORMAT
            || ($manifest['version'] ?? null) !== self::VERSION
            || ! is_array($manifest['files'] ?? null)) {
            $zip->close();

            throw ValidationException::withMessages(['file' => 'La copia es incompatible con esta versión.']);
        }

        $files = $manifest['files'];
        $resources = [];
        $content = null;

        foreach ($names as $name) {
            if ($name === 'manifest.json' || str_ends_with($name, '/')) {
                continue;
            }

            if (! array_key_exists($name, $files)) {
                $zip->close();

                throw ValidationException::withMessages(['file' => 'La copia contiene archivos no declarados o manipulados.']);
            }
        }

        foreach ($files as $name => $meta) {
            $this->assertSafeName($name);
            $data = $zip->getFromName($name);

            if ($data === false
                || ! is_array($meta)
                || ($meta['sha256'] ?? null) !== hash('sha256', $data)
                || (isset($meta['bytes']) && (int) $meta['bytes'] !== strlen($data))) {
                $zip->close();

                throw ValidationException::withMessages(['file' => 'La copia está manipulada o incompleta.']);
            }

            if ($name === 'content.json') {
                $content = json_decode($data, true);
            } elseif (str_starts_with($name, 'resources/')) {
                $resources[$name] = $data;
            }
        }

        $zip->close();

        if (! is_array($content)) {
            throw ValidationException::withMessages(['file' => 'La copia no contiene el contenido esperado.']);
        }

        $this->assertContent($content, $resources);

        return ['content' => $content, 'resources' => $resources];
    }

    private function assertContent(array $content, array $resources): void
    {
        $keys = [
            'cards', 'card_relationships', 'novels', 'parts', 'work_states', 'chapters',
            'chapter_alternatives', 'scenes', 'plots', 'plot_scene', 'styles', 'footnotes',
            'text_references', 'image_maps', 'media_assets', 'fonts', 'novel_goals',
            'content_revisions', 'history_entries', 'notes', 'note_links', 'events',
            'event_relations', 'calendars', 'calendar_conversions', 'story_changes',
            'story_contexts', 'object_custodies', 'resource_origins', 'glossary_terms',
            'character_roles', 'place_containments', 'card_field_definitions',
            'snapshots', 'snapshot_items', 'comments', 'comment_messages',
        ];

        if (! is_array($content['universe'] ?? null)
            || ! is_string($content['universe']['name'] ?? null)
            || trim($content['universe']['name']) === '') {
            $this->invalidContent();
        }

        if (array_diff(array_keys($content), [...$keys, 'universe']) !== []) {
            $this->invalidContent();
        }

        foreach ($keys as $key) {
            if (! isset($content[$key])) {
                $content[$key] = [];
            }

            if (! is_array($content[$key])) {
                $this->invalidContent();
            }
        }

        $ids = function (array $items): array {
            $ids = [];

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id'])) {
                    $this->invalidContent();
                }

                $ids[] = (int) $item['id'];
            }

            return $ids;
        };

        $cardIds = $ids($content['cards']);
        $novelIds = $ids($content['novels']);
        $noteIds = $ids($content['notes']);
        $eventIds = $ids($content['events']);
        $calendarIds = $ids($content['calendars']);
        $snapshotIds = $ids($content['snapshots']);
        $commentIds = $ids($content['comments']);
        $partIds = $ids($content['parts']);
        $stateIds = $ids($content['work_states']);
        $chapterIds = $ids($content['chapters']);
        $alternativeIds = $ids($content['chapter_alternatives']);
        $sceneIds = $ids($content['scenes']);
        $plotIds = $ids($content['plots']);
        $assetIds = $ids($content['media_assets']);

        foreach ([
            $cardIds, $novelIds, $partIds, $stateIds, $chapterIds,
            $alternativeIds, $sceneIds, $plotIds, $assetIds,
        ] as $set) {
            if (count($set) !== count(array_unique($set))) {
                $this->invalidContent();
            }
        }

        $expect = function (int $id, array $allowed): void {
            if (! in_array($id, $allowed, true)) {
                $this->invalidContent();
            }
        };

        foreach ($content['card_relationships'] as $row) {
            $expect((int) $row['source_card_id'], $cardIds);
            $expect((int) $row['target_card_id'], $cardIds);
        }

        foreach ($content['parts'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);
        }

        foreach ($content['work_states'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);
        }

        foreach ($content['chapters'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);

            if (($row['part_id'] ?? null) !== null) {
                $expect((int) $row['part_id'], $partIds);
            }
        }

        foreach ($content['chapter_alternatives'] as $row) {
            $expect((int) $row['chapter_id'], $chapterIds);

            if (($row['work_state_id'] ?? null) !== null) {
                $expect((int) $row['work_state_id'], $stateIds);
            }
        }

        foreach ($content['scenes'] as $row) {
            $expect((int) $row['chapter_alternative_id'], $alternativeIds);

            foreach (['pov_card_id', 'place_card_id'] as $field) {
                if (($row[$field] ?? null) !== null) {
                    $expect((int) $row[$field], $cardIds);
                }
            }
        }

        foreach ($content['plots'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);
        }

        foreach ($content['plot_scene'] as $row) {
            $expect((int) $row['plot_id'], $plotIds);
            $expect((int) $row['scene_id'], $sceneIds);
        }

        foreach ($content['styles'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);
        }

        foreach ($content['footnotes'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);

            if (($row['scene_id'] ?? null) !== null) {
                $expect((int) $row['scene_id'], $sceneIds);
            }
        }

        foreach ($content['text_references'] as $row) {
            $expect((int) $row['scene_id'], $sceneIds);
            $expect((int) $row['card_id'], $cardIds);
        }

        foreach ($content['snapshots'] as $row) {
            $expect((int) ($row['novel_id'] ?? 0), $novelIds);

            if (is_string($row['manifest'] ?? null)) {
                $row['manifest'] = json_decode($row['manifest'], true);
            }

            if (! is_array($row['manifest'] ?? null) || ! is_string($row['name'] ?? null)) {
                $this->invalidContent();
            }
        }

        foreach ($content['snapshot_items'] as $row) {
            $expect((int) ($row['snapshot_id'] ?? 0), $snapshotIds);

            if (! is_string($row['resource_type'] ?? null)
                || ! is_numeric($row['resource_id'] ?? null)
                || ! is_numeric($row['revision'] ?? null)) {
                $this->invalidContent();
            }
        }

        foreach ($content['comments'] as $row) {
            $expect((int) ($row['novel_id'] ?? 0), $novelIds);

            if (($row['chapter_id'] ?? null) !== null) {
                $expect((int) $row['chapter_id'], $chapterIds);
            }

            if (($row['scene_id'] ?? null) !== null) {
                $expect((int) $row['scene_id'], $sceneIds);
            }

            if (($row['snapshot_id'] ?? null) !== null) {
                $expect((int) $row['snapshot_id'], $snapshotIds);
            }
        }

        foreach ($content['comment_messages'] as $row) {
            $expect((int) ($row['comment_id'] ?? 0), $commentIds);

            if (! is_string($row['body'] ?? null)) {
                $this->invalidContent();
            }
        }

        foreach ($content['notes'] as $row) {
            if (($row['novel_id'] ?? null) !== null) {
                $expect((int) $row['novel_id'], $novelIds);
            }
        }

        foreach ($content['note_links'] as $row) {
            $expect((int) ($row['note_id'] ?? 0), $noteIds);

            if (! in_array($row['linkable_type'] ?? null, ['card', 'scene', 'chapter'], true)
                || ! is_numeric($row['linkable_id'] ?? null)) {
                $this->invalidContent();
            }
        }

        foreach ($content['event_relations'] as $row) {
            $expect((int) ($row['from_event_id'] ?? 0), $eventIds);
            $expect((int) ($row['to_event_id'] ?? 0), $eventIds);
        }

        foreach ($content['calendar_conversions'] as $row) {
            $expect((int) ($row['from_calendar_id'] ?? 0), $calendarIds);
            $expect((int) ($row['to_calendar_id'] ?? 0), $calendarIds);
        }

        foreach ($content['story_changes'] as $row) {
            $expect((int) ($row['card_id'] ?? 0), $cardIds);

            if (($row['effective_event_id'] ?? null) !== null) {
                $expect((int) $row['effective_event_id'], $eventIds);
            }
        }

        foreach ($content['story_contexts'] as $row) {
            if (($row['novel_id'] ?? null) !== null) {
                $expect((int) $row['novel_id'], $novelIds);
            }

            if (($row['scene_id'] ?? null) !== null) {
                $expect((int) $row['scene_id'], $sceneIds);
            }

            if (($row['card_id'] ?? null) !== null) {
                $expect((int) $row['card_id'], $cardIds);
            }

            if (($row['event_id'] ?? null) !== null) {
                $expect((int) $row['event_id'], $eventIds);
            }
        }

        foreach ($content['object_custodies'] as $row) {
            $expect((int) ($row['card_id'] ?? 0), $cardIds);

            if (($row['holder_card_id'] ?? null) !== null) {
                $expect((int) $row['holder_card_id'], $cardIds);
            }

            if (($row['event_id'] ?? null) !== null) {
                $expect((int) $row['event_id'], $eventIds);
            }
        }

        foreach ($content['resource_origins'] as $row) {
            $expect((int) ($row['card_id'] ?? 0), $cardIds);

            if (($row['place_card_id'] ?? null) !== null) {
                $expect((int) $row['place_card_id'], $cardIds);
            }
        }

        foreach ($content['glossary_terms'] as $row) {
            if (($row['language_card_id'] ?? null) !== null) {
                $expect((int) $row['language_card_id'], $cardIds);
            }
        }

        foreach ($content['character_roles'] as $row) {
            $expect((int) ($row['novel_id'] ?? 0), $novelIds);
            $expect((int) ($row['card_id'] ?? 0), $cardIds);
        }

        foreach ($content['place_containments'] as $row) {
            $expect((int) ($row['parent_card_id'] ?? 0), $cardIds);
            $expect((int) ($row['child_card_id'] ?? 0), $cardIds);
        }

        foreach ($content['content_revisions'] as $row) {
            $expect((int) ($row['scene_id'] ?? 0), $sceneIds);

            if (! is_numeric($row['revision'] ?? null)
                || (! is_string($row['content'] ?? null) && ! is_array($row['content'] ?? null))) {
                $this->invalidContent();
            }
        }

        foreach ($content['history_entries'] as $row) {
            $expect((int) ($row['novel_id'] ?? 0), $novelIds);

            if (! is_string($row['resource_type'] ?? null)
                || ! is_numeric($row['resource_id'] ?? null)
                || ! is_numeric($row['revision'] ?? null)
                || (! is_string($row['payload'] ?? null) && ! is_array($row['payload'] ?? null))) {
                $this->invalidContent();
            }
        }

        foreach ($content['media_assets'] as $row) {
            if (! is_string($row['resource'] ?? null) || ! isset($resources[$row['resource']])) {
                $this->invalidContent();
            }

            if (($row['novel_id'] ?? null) !== null) {
                $expect((int) $row['novel_id'], $novelIds);
            }
        }

        foreach ($content['fonts'] as $row) {
            if (! is_string($row['resource'] ?? null) || ! isset($resources[$row['resource']])) {
                $this->invalidContent();
            }
        }

        foreach ($content['image_maps'] as $row) {
            $expect((int) $row['image_asset_id'], $assetIds);

            if (($row['novel_id'] ?? null) !== null) {
                $expect((int) $row['novel_id'], $novelIds);
            }

            foreach (($row['markers'] ?? []) as $marker) {
                if (is_array($marker) && isset($marker['place_card_id'])) {
                    $expect((int) $marker['place_card_id'], $cardIds);
                }
            }
        }

        foreach ($content['novel_goals'] as $row) {
            $expect((int) $row['novel_id'], $novelIds);
        }
    }

    private function collectFontIds(mixed $value, array &$ids): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && str_contains(strtolower($key), 'font') && is_int($item)) {
                $ids[$item] = $item;
            } elseif (is_array($item)) {
                $this->collectFontIds($item, $ids);
            }
        }
    }

    private function remapSnapshotManifest(
        array $manifest,
        array $novelMap,
        array $partMap,
        array $stateMap,
        array $chapterMap,
        array $alternativeMap,
        array $sceneMap,
        array $styleMap,
        array $fontMap,
        array $footnoteMap,
        array $assetMap,
    ): array {
        if (isset($manifest['novel']['id'])) {
            $manifest['novel']['id'] = $novelMap[(int) $manifest['novel']['id']] ?? $manifest['novel']['id'];
        }

        foreach (($manifest['chapters'] ?? []) as $index => $chapter) {
            $manifest['chapters'][$index]['id'] = $chapterMap[(int) ($chapter['id'] ?? 0)] ?? ($chapter['id'] ?? null);
            $manifest['chapters'][$index]['part_id'] = isset($chapter['part_id']) ? ($partMap[(int) $chapter['part_id']] ?? null) : null;

            foreach (($chapter['alternatives'] ?? []) as $altIndex => $alternative) {
                $manifest['chapters'][$index]['alternatives'][$altIndex]['id'] =
                    $alternativeMap[(int) ($alternative['id'] ?? 0)] ?? ($alternative['id'] ?? null);
                $manifest['chapters'][$index]['alternatives'][$altIndex]['work_state_id'] =
                    isset($alternative['work_state_id']) ? ($stateMap[(int) $alternative['work_state_id']] ?? null) : null;

                foreach (($alternative['scenes'] ?? []) as $sceneIndex => $scene) {
                    $manifest['chapters'][$index]['alternatives'][$altIndex]['scenes'][$sceneIndex]['id'] =
                        $sceneMap[(int) ($scene['id'] ?? 0)] ?? ($scene['id'] ?? null);
                    $manifest['chapters'][$index]['alternatives'][$altIndex]['scenes'][$sceneIndex]['content'] =
                        is_array($scene['content'] ?? null)
                            ? $this->remapSceneContent($scene['content'], $assetMap, $styleMap, $footnoteMap, $fontMap)
                            : ($scene['content'] ?? null);
                }
            }
        }

        foreach (($manifest['styles'] ?? []) as $index => $style) {
            $manifest['styles'][$index]['id'] = $styleMap[(int) ($style['id'] ?? 0)] ?? ($style['id'] ?? null);
            $manifest['styles'][$index]['definition'] = $this->remapFontIds($style['definition'] ?? [], $fontMap);
        }

        foreach (($manifest['resources']['fonts'] ?? []) as $index => $font) {
            $manifest['resources']['fonts'][$index]['id'] = $fontMap[(int) ($font['id'] ?? 0)] ?? ($font['id'] ?? null);
        }

        foreach (($manifest['resources']['media'] ?? []) as $index => $media) {
            $manifest['resources']['media'][$index]['id'] = $assetMap[(int) ($media['id'] ?? 0)] ?? ($media['id'] ?? null);
        }

        foreach (($manifest['notes'] ?? []) as $index => $note) {
            $manifest['notes'][$index]['id'] = $footnoteMap[(int) ($note['id'] ?? 0)] ?? ($note['id'] ?? null);
            $manifest['notes'][$index]['scene_id'] = isset($note['scene_id'])
                ? ($sceneMap[(int) $note['scene_id']] ?? null)
                : null;
        }

        return $manifest;
    }

    private function remapSnapshotPayload(
        string $type,
        array $payload,
        array $novelMap,
        array $partMap,
        array $stateMap,
        array $chapterMap,
        array $alternativeMap,
        array $sceneMap,
        array $styleMap,
        array $cardMap,
        array $footnoteMap,
        array $fontMap,
        array $assetMap,
    ): array {
        if (isset($payload['id'])) {
            $map = match ($type) {
                'novel' => $novelMap,
                'part' => $partMap,
                'work_state' => $stateMap,
                'chapter' => $chapterMap,
                'chapter_alternative' => $alternativeMap,
                'scene' => $sceneMap,
                'style' => $styleMap,
                default => [],
            };
            $payload['id'] = $map[(int) $payload['id']] ?? $payload['id'];
        }

        if (isset($payload['novel_id'])) {
            $payload['novel_id'] = $novelMap[(int) $payload['novel_id']] ?? null;
        }
        if (isset($payload['part_id'])) {
            $payload['part_id'] = $partMap[(int) $payload['part_id']] ?? null;
        }
        if (isset($payload['chapter_id'])) {
            $payload['chapter_id'] = $chapterMap[(int) $payload['chapter_id']] ?? null;
        }
        if (isset($payload['chapter_alternative_id'])) {
            $payload['chapter_alternative_id'] = $alternativeMap[(int) $payload['chapter_alternative_id']] ?? null;
        }
        if (isset($payload['work_state_id'])) {
            $payload['work_state_id'] = $stateMap[(int) $payload['work_state_id']] ?? null;
        }
        if (isset($payload['pov_card_id'])) {
            $payload['pov_card_id'] = $cardMap[(int) $payload['pov_card_id']] ?? null;
        }
        if (isset($payload['place_card_id'])) {
            $payload['place_card_id'] = $cardMap[(int) $payload['place_card_id']] ?? null;
        }
        if ($type === 'style' && isset($payload['definition'])) {
            $payload['definition'] = $this->remapFontIds($payload['definition'], $fontMap);
        }
        if ($type === 'scene' && is_array($payload['content'] ?? null)) {
            $payload['content'] = $this->remapSceneContent($payload['content'], $assetMap, $styleMap, $footnoteMap, $fontMap);
        }

        return $payload;
    }

    private function remapJsonIds(mixed $value, array $map): mixed
    {
        $wasString = is_string($value);
        $data = $wasString ? json_decode($value, true) : $value;

        if (! is_array($data)) {
            return $value;
        }

        $walk = function (&$item) use (&$walk, $map): void {
            if (is_array($item)) {
                foreach ($item as &$child) {
                    $walk($child);
                }
                unset($child);

                return;
            }

            if (is_numeric($item) && isset($map[(int) $item])) {
                $item = $map[(int) $item];
            }
        };

        $walk($data);

        return $wasString ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
    }

    private function remapSceneContent(array $content, array $assetMap, array $styleMap, array $footnoteMap, array $fontMap): array
    {
        foreach ($content as $key => &$value) {
            if (is_array($value)) {
                if (($value['type'] ?? null) === 'character_style'
                    && isset($value['attrs']['id'])
                    && isset($styleMap[(int) $value['attrs']['id']])) {
                    $value['attrs']['id'] = (string) $styleMap[(int) $value['attrs']['id']];
                }

                $value = $this->remapSceneContent($value, $assetMap, $styleMap, $footnoteMap, $fontMap);

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $lower = strtolower($key);

            if (in_array($lower, ['asset_id', 'image_asset_id', 'media_id'], true) && is_numeric($value)) {
                $value = $assetMap[(int) $value] ?? $value;
            } elseif ($lower === 'note_id' && is_numeric($value)) {
                $value = $footnoteMap[(int) $value] ?? $value;
            } elseif (is_string($value) && preg_match('/^font:(\d+)$/', $value, $match) === 1) {
                $value = 'font:'.($fontMap[(int) $match[1]] ?? (int) $match[1]);
            } elseif (str_contains($lower, 'font') && is_numeric($value)) {
                $value = $fontMap[(int) $value] ?? $value;
            }
        }
        unset($value);

        return $content;
    }

    private function remapFontIds(mixed $value, array $fontMap): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && str_contains(strtolower($key), 'font') && is_int($item) && isset($fontMap[$item])) {
                $value[$key] = $fontMap[$item];
            } elseif (is_array($item)) {
                $value[$key] = $this->remapFontIds($item, $fontMap);
            }
        }

        return $value;
    }

    private function safeName(string $name, string $fallback): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name)) ?: '';

        return $name === '' || $name === '.' || $name === '..' ? $fallback : $name;
    }

    private function assertSafeName(string $name): void
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            throw ValidationException::withMessages([
                'file' => 'La copia contiene rutas no permitidas.',
            ]);
        }
    }

    private function invalidContent(): never
    {
        throw ValidationException::withMessages([
            'file' => 'La copia contiene relaciones internas no válidas.',
        ]);
    }
}
