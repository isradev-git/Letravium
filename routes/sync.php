<?php

use App\Http\Controllers\SyncContractController;
use App\Http\Controllers\SyncDeviceController;
use App\Http\Controllers\SyncOperationController;
use Illuminate\Support\Facades\Route;

Route::get('/sync/desktop-contract', [SyncContractController::class, 'show']);

Route::middleware(['private.access', 'creative'])->group(function () {
    Route::post('/sync/devices', [SyncDeviceController::class, 'store']);
    Route::get('/sync/devices/{device}', [SyncDeviceController::class, 'show']);
    Route::delete('/sync/devices/{device}', [SyncDeviceController::class, 'destroy']);

    Route::post('/sync/operations', [SyncOperationController::class, 'store']);
    Route::post('/sync/conflicts/{conflict}/resolve', [SyncOperationController::class, 'resolve']);
});
