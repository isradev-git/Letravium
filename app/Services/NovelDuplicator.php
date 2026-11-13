<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterAlternative;
use App\Models\Footnote;
use App\Models\Novel;
use App\Models\Part;
use App\Models\Plot;
use App\Models\Scene;
use App\Models\Style;
use App\Models\TextReference;
use App\Models\WorkState;
use Illuminate\Support\Facades\DB;

class NovelDuplicator
{
    public function duplicate(Novel $novel, int $ownerId): Novel
    {
        return DB::transaction(function () use ($novel, $ownerId) {
            $copy = Novel::create([
                'universe_id' => $novel->universe_id,
                'owner_id' => $ownerId,
                'title' => $novel->title.' (copia)',
                'settings' => $novel->settings,
            ]);

            $styleMap = [];

            foreach ($novel->styles()->get() as $style) {
                $newStyle = Style::create([
                    'novel_id' => $copy->id,
                    'name' => $style->name,
                    'kind' => $style->kind,
                    'definition' => $style->definition,
                ]);
                $styleMap[$style->id] = $newStyle->id;
            }

            $partMap = [];
            foreach ($novel->parts as $part) {
                $newPart = Part::create([
                    'novel_id' => $copy->id,
                    'title' => $part->title,
                    'position' => $part->position,
                ]);
                $partMap[$part->id] = $newPart->id;
            }

            $stateMap = [];
            foreach ($novel->workStates as $state) {
                $newState = WorkState::create([
                    'novel_id' => $copy->id,
                    'name' => $state->name,
                    'position' => $state->position,
                ]);
                $stateMap[$state->id] = $newState->id;
            }

            $sceneMap = [];
            $sceneBodies = [];

            foreach ($novel->chapters as $chapter) {
                $newChapter = Chapter::create([
                    'novel_id' => $copy->id,
                    'part_id' => $partMap[$chapter->part_id] ?? null,
                    'title' => $chapter->title,
                    'position' => $chapter->position,
                    'plan' => $chapter->plan,
                ]);

                foreach ($chapter->alternatives as $alternative) {
                    $newAlternative = ChapterAlternative::create([
                        'chapter_id' => $newChapter->id,
                        'work_state_id' => $stateMap[$alternative->work_state_id] ?? null,
                        'alias' => $alternative->alias,
                        'is_active' => $alternative->is_active,
                        'plan' => $alternative->plan,
                    ]);

                    foreach ($alternative->scenes as $scene) {
                        $newScene = Scene::create([
                            'chapter_alternative_id' => $newAlternative->id,
                            'title' => $scene->title,
                            'position' => $scene->position,
                            'summary' => $scene->summary,
                            'pov_card_id' => $scene->pov_card_id,
                            'place_card_id' => $scene->place_card_id,
                            'narrative_moment' => $scene->narrative_moment,
                            'plan' => $scene->plan,
                            'content' => $scene->content,
                        ]);
                        $sceneMap[$scene->id] = $newScene->id;

                        if (is_array($scene->content)) {
                            $sceneBodies[$newScene->id] = $scene->content;
                        }
                    }
                }
            }

            $footnoteMap = [];

            foreach ($novel->footnotes()->get() as $note) {
                $newNote = Footnote::create([
                    'novel_id' => $copy->id,
                    'scene_id' => $note->scene_id === null ? null : ($sceneMap[$note->scene_id] ?? null),
                    'kind' => $note->kind,
                    'number' => $note->number,
                    'text' => $note->text,
                    'content' => $note->content,
                ]);
                $footnoteMap[$note->id] = $newNote->id;
            }

            foreach ($sceneBodies as $sceneId => $body) {
                Scene::whereKey($sceneId)->update([
                    'content' => $this->remapStyleAndNoteIds($body, $styleMap, $footnoteMap),
                ]);
            }

            $plots = Plot::where('novel_id', $novel->id)->orderBy('id')->get();
            $plotMap = [];

            foreach ($plots as $plot) {
                $newPlot = Plot::create([
                    'novel_id' => $copy->id,
                    'name' => $plot->name,
                    'description' => $plot->description,
                    'notes' => $plot->notes,
                ]);
                $plotMap[$plot->id] = $newPlot->id;
            }

            if ($plotMap !== []) {
                foreach (DB::table('plot_scene')->whereIn('plot_id', array_keys($plotMap))->get() as $link) {
                    if (isset($sceneMap[$link->scene_id])) {
                        DB::table('plot_scene')->insertOrIgnore([
                            'plot_id' => $plotMap[$link->plot_id],
                            'scene_id' => $sceneMap[$link->scene_id],
                        ]);
                    }
                }
            }

            if ($sceneMap !== []) {
                foreach (TextReference::whereIn('scene_id', array_keys($sceneMap))->get() as $reference) {
                    DB::table('text_references')->insertOrIgnore([
                        'scene_id' => $sceneMap[$reference->scene_id],
                        'card_id' => $reference->card_id,
                        'created_by' => $reference->created_by ?? $ownerId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $copy;
        });
    }

    private function remapStyleAndNoteIds(array $content, array $styleMap, array $footnoteMap): array
    {
        foreach ($content as $key => &$value) {
            if (is_array($value)) {
                if (($value['type'] ?? null) === 'character_style'
                    && isset($value['attrs']['id'])
                    && isset($styleMap[(int) $value['attrs']['id']])) {
                    $value['attrs']['id'] = (string) $styleMap[(int) $value['attrs']['id']];
                }

                $value = $this->remapStyleAndNoteIds($value, $styleMap, $footnoteMap);

                continue;
            }

            if ($key === 'note_id' && is_numeric($value) && isset($footnoteMap[(int) $value])) {
                $value = $footnoteMap[(int) $value];
            }
        }
        unset($value);

        return $content;
    }
}
