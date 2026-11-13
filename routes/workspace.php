<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ReplacementController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/novels/{novel}', [EditorController::class, 'showNovel']);
    Route::get('/workspace/novels/{novel}/planning', [PlanningController::class, 'show']);
    Route::get('/workspace/universes', [WorkspaceController::class, 'universes']);
    Route::post('/workspace/universes', [WorkspaceController::class, 'storeUniverse']);
    Route::post('/workspace/universes/{universe}/novels', [WorkspaceController::class, 'storeNovel']);
    Route::post('/workspace/universes/{universe}/duplicate', [WorkspaceController::class, 'duplicateUniverse']);
    Route::patch('/workspace/universes/{universe}', [WorkspaceController::class, 'updateUniverse']);
    Route::post('/workspace/novels/{novel}/duplicate', [WorkspaceController::class, 'duplicateNovel']);
    Route::patch('/workspace/novels/{novel}', [WorkspaceController::class, 'updateNovel']);
    Route::patch('/workspace/novels/{novel}/archive', [WorkspaceController::class, 'archiveNovel']);
    Route::patch('/workspace/novels/{novel}/unarchive', [WorkspaceController::class, 'unarchiveNovel']);
    Route::post('/workspace/collections', [WorkspaceController::class, 'storeCollection']);
    Route::patch('/workspace/collections/{collection}', [WorkspaceController::class, 'updateCollection']);
    Route::delete('/workspace/collections/{collection}', [WorkspaceController::class, 'destroyCollection']);
    Route::post('/workspace/collections/{collection}/novels/{novel}', [WorkspaceController::class, 'addNovel']);
    Route::delete('/workspace/collections/{collection}/novels/{novel}', [WorkspaceController::class, 'removeNovel']);
    Route::patch('/workspace/collections/{collection}/novels/{novel}/move', [WorkspaceController::class, 'moveNovel']);

    Route::get('/workspace/novels/{novel}/chapters', [ContentController::class, 'chapters']);
    Route::get('/workspace/novels/{novel}/manuscript', [ContentController::class, 'manuscript']);
    Route::get('/workspace/chapters/{chapter}', [ContentController::class, 'chapterView']);
    Route::get('/workspace/novels/{novel}/search', [SearchController::class, 'search']);
    Route::post('/workspace/novels/{novel}/replace/preview', [ReplacementController::class, 'preview']);
    Route::post('/workspace/novels/{novel}/replace', [ReplacementController::class, 'apply']);
    Route::get('/workspace/chapters/{chapter}/search', [SearchController::class, 'searchChapter']);
    Route::get('/workspace/universes/{universe}/search', [SearchController::class, 'searchUniverse']);
    Route::post('/workspace/novels/{novel}/parts', [ContentController::class, 'storePart']);
    Route::post('/workspace/novels/{novel}/chapters', [ContentController::class, 'storeChapter']);
    Route::patch('/workspace/novels/{novel}/chapters/order', [ContentController::class, 'reorderChapters']);
    Route::post('/workspace/chapters/{chapter}/alternatives', [ContentController::class, 'storeAlternative']);
    Route::patch('/workspace/alternatives/{alternative}/activate', [ContentController::class, 'activateAlternative']);
    Route::put('/workspace/chapters/{chapter}/plan', [ContentController::class, 'updateChapterPlan']);
    Route::put('/workspace/alternatives/{alternative}/plan', [ContentController::class, 'updateAlternativePlan']);
    Route::post('/workspace/alternatives/{alternative}/scenes', [ContentController::class, 'storeScene']);
    Route::put('/workspace/scenes/{scene}', [ContentController::class, 'updateScene']);

    Route::post('/workspace/novels/{novel}/plots', [ContentController::class, 'storePlot']);
    Route::post('/workspace/plots/{plot}/scenes/{scene}', [ContentController::class, 'attachPlot']);
    Route::post('/workspace/scenes/{scene}/plots', [ContentController::class, 'attachScenePlot']);
    Route::post('/workspace/novels/{novel}/work-states', [ContentController::class, 'storeWorkState']);
    Route::patch('/workspace/work-states/{state}', [ContentController::class, 'updateWorkState']);
    Route::put('/workspace/alternatives/{alternative}/work-state', [ContentController::class, 'assignWorkState']);
    Route::get('/workspace/novels/{novel}/word-count', [ContentController::class, 'wordCount']);
    Route::put('/workspace/novels/{novel}/goal', [ContentController::class, 'setGoal']);

    Route::get('/workspace/universes/{universe}/cards', [CardController::class, 'index']);
    Route::post('/workspace/universes/{universe}/cards', [CardController::class, 'store']);
    Route::patch('/workspace/cards/{card}', [CardController::class, 'update']);
    Route::get('/workspace/universes/{universe}/relationships', [CardController::class, 'relationships']);
    Route::post('/workspace/universes/{universe}/relationships', [CardController::class, 'storeRelationship']);
    Route::get('/workspace/scenes/{scene}/references', [CardController::class, 'references']);
    Route::post('/workspace/scenes/{scene}/references', [CardController::class, 'storeReference']);
});
