<?php

use App\Http\Controllers\FontController;
use App\Http\Controllers\FootnoteController;
use App\Http\Controllers\ImageMapController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\StyleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/novels/{novel}/styles', [StyleController::class, 'index']);
    Route::post('/workspace/novels/{novel}/styles', [StyleController::class, 'store']);
    Route::patch('/workspace/styles/{style}', [StyleController::class, 'update']);
    Route::post('/workspace/styles/{style}/duplicate', [StyleController::class, 'duplicate']);
    Route::delete('/workspace/styles/{style}', [StyleController::class, 'destroy']);

    Route::get('/workspace/novels/{novel}/footnotes', [FootnoteController::class, 'index']);
    Route::post('/workspace/novels/{novel}/footnotes', [FootnoteController::class, 'store']);
    Route::patch('/workspace/footnotes/{footnote}', [FootnoteController::class, 'update']);
    Route::get('/workspace/scenes/{scene}/notes/audit', [FootnoteController::class, 'auditScene']);

    Route::get('/workspace/universes/{universe}/documents', [MediaAssetController::class, 'documents']);
    Route::get('/workspace/assets', [MediaAssetController::class, 'index']);
    Route::post('/workspace/assets', [MediaAssetController::class, 'store']);
    Route::get('/workspace/assets/{asset}/download', [MediaAssetController::class, 'download']);
    Route::get('/workspace/assets/{asset}/view', [MediaAssetController::class, 'view']);
    Route::delete('/workspace/assets/{asset}', [MediaAssetController::class, 'destroy']);

    Route::get('/workspace/fonts', [FontController::class, 'index']);
    Route::post('/workspace/fonts', [FontController::class, 'store']);
    Route::get('/workspace/fonts/{font}/view', [FontController::class, 'view']);
    Route::delete('/workspace/fonts/{font}', [FontController::class, 'destroy']);

    Route::get('/workspace/universes/{universe}/image-maps', [ImageMapController::class, 'indexUniverse']);
    Route::post('/workspace/universes/{universe}/image-maps', [ImageMapController::class, 'storeUniverse']);
    Route::get('/workspace/novels/{novel}/image-maps', [ImageMapController::class, 'indexNovel']);
    Route::post('/workspace/novels/{novel}/image-maps', [ImageMapController::class, 'storeNovel']);
    Route::patch('/workspace/image-maps/{map}', [ImageMapController::class, 'update']);
    Route::post('/workspace/image-maps/{map}/markers', [ImageMapController::class, 'storeMarker']);
});
