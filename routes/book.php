<?php

use App\Http\Controllers\BookProfileController;
use App\Http\Controllers\BookSetupController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/novels/{novel}/book', [ExportController::class, 'screen']);
    Route::get('/workspace/novels/{novel}/book/profiles', [BookProfileController::class, 'index']);
    Route::post('/workspace/novels/{novel}/book/profiles', [BookProfileController::class, 'store']);
    Route::patch('/workspace/book/profiles/{profile}', [BookProfileController::class, 'update']);
    Route::post('/workspace/book/profiles/{profile}/duplicate', [BookProfileController::class, 'duplicate']);
    Route::delete('/workspace/book/profiles/{profile}', [BookProfileController::class, 'destroy']);

    Route::get('/workspace/novels/{novel}/book/metadata', [BookSetupController::class, 'metadata']);
    Route::put('/workspace/novels/{novel}/book/metadata', [BookSetupController::class, 'updateMetadata']);

    Route::get('/workspace/novels/{novel}/book/sections', [BookSetupController::class, 'sections']);
    Route::post('/workspace/novels/{novel}/book/sections', [BookSetupController::class, 'storeSection']);
    Route::patch('/workspace/book/sections/{section}', [BookSetupController::class, 'updateSection']);
    Route::delete('/workspace/book/sections/{section}', [BookSetupController::class, 'destroySection']);

    Route::get('/workspace/novels/{novel}/book/marks', [BookSetupController::class, 'marks']);
    Route::post('/workspace/novels/{novel}/book/marks', [BookSetupController::class, 'storeMark']);
    Route::patch('/workspace/book/marks/{mark}', [BookSetupController::class, 'updateMark']);
    Route::delete('/workspace/book/marks/{mark}', [BookSetupController::class, 'destroyMark']);

    Route::get('/workspace/novels/{novel}/book/navigation', [BookSetupController::class, 'navigation']);
    Route::get('/workspace/novels/{novel}/book/preflight', [BookSetupController::class, 'preflight']);

    Route::post('/workspace/novels/{novel}/export-manifests', [ExportController::class, 'store']);
    Route::get('/workspace/export-manifests/{manifest}', [ExportController::class, 'show']);
    Route::get('/workspace/export-manifests/{manifest}/pdf', [ExportController::class, 'pdf']);
    Route::get('/workspace/export-manifests/{manifest}/pdf-resource/{kind}/{id}', [ExportController::class, 'pdfResource']);
    Route::get('/workspace/export-manifests/{manifest}/{format}', [ExportController::class, 'export']);
});
