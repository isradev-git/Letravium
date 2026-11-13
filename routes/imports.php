<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/imports', [ImportController::class, 'create']);
    Route::get('/workspace/imports/preview/{token}', [ImportController::class, 'showPreview']);
    Route::post('/workspace/imports/preview', [ImportController::class, 'preview']);
    Route::post('/workspace/imports/commit', [ImportController::class, 'commit']);

    Route::get('/workspace/backups', [BackupController::class, 'index']);
    Route::post('/workspace/backups/export', [BackupController::class, 'export']);
    Route::post('/workspace/backups/restore', [BackupController::class, 'restore']);
});
