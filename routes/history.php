<?php

use App\Http\Controllers\AccountClosureController;
use App\Http\Controllers\CardFieldController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\TrashController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'private.access', 'creative'])->group(function () {
    Route::put('/workspace/scenes/{scene}/content', [HistoryController::class, 'saveScene']);
    Route::put('/workspace/chapters/{chapter}/content', [HistoryController::class, 'saveChapter']);
    Route::post('/workspace/scenes/{scene}/pending-edits', [HistoryController::class, 'storePendingEdit']);
    Route::get('/workspace/scenes/{scene}/pending-edits', [HistoryController::class, 'pendingEdits']);
    Route::get('/workspace/scenes/{scene}/revisions', [HistoryController::class, 'revisions']);
    Route::post('/workspace/scenes/{scene}/split', [HistoryController::class, 'splitScene']);
    Route::post('/workspace/scenes/{scene}/merge', [HistoryController::class, 'mergeScene']);

    Route::get('/workspace/universes/{universe}/card-fields', [CardFieldController::class, 'index']);
    Route::post('/workspace/universes/{universe}/card-fields', [CardFieldController::class, 'store']);
    Route::patch('/workspace/card-fields/{fieldId}', [CardFieldController::class, 'update']);
    Route::delete('/workspace/card-fields/{fieldId}', [CardFieldController::class, 'destroy']);

    Route::get('/workspace/trash', [TrashController::class, 'index']);
    Route::post('/workspace/{type}/{id}/trash', [TrashController::class, 'trash']);
    Route::post('/workspace/trash/{entryId}/restore', [TrashController::class, 'restore']);
    Route::get('/workspace/trash/{entryId}/dependencies', [TrashController::class, 'dependencies']);
    Route::delete('/workspace/trash/{entryId}', [TrashController::class, 'destroy']);
});

Route::middleware(['auth', 'private.access'])->group(function () {
    Route::get('/account/close', [AccountClosureController::class, 'show']);
    Route::get('/account/export', [AccountClosureController::class, 'export']);
    Route::post('/account/close', [AccountClosureController::class, 'close'])->middleware('recent.auth');
});
