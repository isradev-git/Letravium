<?php

use App\Http\Controllers\CollaborationReadController;
use App\Http\Controllers\CreativePermissionController;
use App\Http\Controllers\EditLeaseController;
use App\Http\Controllers\MultiElementController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::middleware('private.access')->prefix('workspace/collaboration')->group(function () {
    Route::post('/permissions', [CreativePermissionController::class, 'store']);
    Route::delete('/permissions/{permission}', [CreativePermissionController::class, 'destroy']);

    Route::get('/novels/{novel}', [CollaborationReadController::class, 'novel']);
    Route::get('/novels/{novel}/chapters', [CollaborationReadController::class, 'chapters']);
    Route::get('/novels/{novel}/chapters/{chapter}', [CollaborationReadController::class, 'chapter']);
    Route::get('/novels/{novel}/search', [CollaborationReadController::class, 'search']);
    Route::get('/novels/{novel}/history', [CollaborationReadController::class, 'history']);
    Route::get('/novels/{novel}/export', [CollaborationReadController::class, 'export']);
    Route::get('/novels/{novel}/assets/{asset}/download', [CollaborationReadController::class, 'download']);
    Route::get('/cards/{card}', [CollaborationReadController::class, 'card']);

    Route::get('/leases/{type}/{id}', [EditLeaseController::class, 'show']);
    Route::post('/leases/{type}/{id}', [EditLeaseController::class, 'acquire']);
    Route::delete('/leases/{type}/{id}', [EditLeaseController::class, 'release']);
    Route::post('/leases/{type}/{id}/expire', [EditLeaseController::class, 'expire']);

    Route::patch('/chapters/{chapter}', [EditLeaseController::class, 'updateChapter']);
    Route::patch('/cards/{card}', [EditLeaseController::class, 'updateCard']);

    Route::post('/batch', [MultiElementController::class, 'store']);

    Route::get('/universes/{universe}/accepted-words', [VocabularyController::class, 'indexUniverse']);
    Route::post('/universes/{universe}/accepted-words', [VocabularyController::class, 'storeWord']);
    Route::delete('/universes/{universe}/accepted-words/{word}', [VocabularyController::class, 'destroyWord']);
    Route::post('/universes/{universe}/vocabulary-grants', [VocabularyController::class, 'grant']);
    Route::delete('/vocabulary-grants/{grant}', [VocabularyController::class, 'revoke']);
    Route::get('/novels/{novel}/vocabulary', [VocabularyController::class, 'indexNovel']);
    Route::post('/novels/{novel}/spelling/check', [VocabularyController::class, 'check']);
});
