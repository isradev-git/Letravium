<?php

use App\Http\Controllers\EditorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::post('/workspace/editor/sanitize-paste', [EditorController::class, 'sanitizePaste']);
});
