<?php

use App\Http\Controllers\BetaReaderController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CorrectionController;
use App\Http\Controllers\SnapshotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::get('/workspace/novels/{novel}/snapshots', [SnapshotController::class, 'index']);
    Route::post('/workspace/novels/{novel}/snapshots', [SnapshotController::class, 'store']);
    Route::get('/workspace/snapshots/{snapshot}', [SnapshotController::class, 'show']);
    Route::patch('/workspace/snapshots/{snapshot}', [SnapshotController::class, 'rename']);
    Route::get('/workspace/snapshots/{snapshot}/compare', [SnapshotController::class, 'compare']);
    Route::get('/workspace/snapshots/{snapshot}/restore-preview', [SnapshotController::class, 'restorePreview']);
    Route::post('/workspace/snapshots/{snapshot}/restore-fragment', [SnapshotController::class, 'restoreFragment']);
    Route::post('/workspace/snapshots/{snapshot}/restore', [SnapshotController::class, 'restore']);

    Route::get('/workspace/snapshots/{snapshot}/beta-readers', [BetaReaderController::class, 'index']);
    Route::post('/workspace/snapshots/{snapshot}/beta-readers', [BetaReaderController::class, 'store']);
    Route::patch('/workspace/beta-readers/{reader}', [BetaReaderController::class, 'update']);
    Route::delete('/workspace/beta-readers/{reader}', [BetaReaderController::class, 'destroy']);
});

Route::middleware('private.access')->group(function () {
    Route::get('/workspace/novels/{novel}/comments', [CommentController::class, 'index']);
    Route::post('/workspace/novels/{novel}/comments', [CommentController::class, 'store']);
    Route::post('/workspace/comments/{comment}/messages', [CommentController::class, 'addMessage']);
    Route::patch('/workspace/comments/{comment}', [CommentController::class, 'update']);

    Route::post('/workspace/comments/{comment}/corrections', [CorrectionController::class, 'store']);
    Route::post('/workspace/corrections/{proposal}/accept', [CorrectionController::class, 'accept']);
    Route::post('/workspace/corrections/{proposal}/reject', [CorrectionController::class, 'reject']);

    Route::get('/workspace/beta/{token}', [BetaReaderController::class, 'show']);
});
