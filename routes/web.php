<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$entry = fn (Request $request) => $request->user()
    ? redirect($request->user()->homePath())
    : view('auth.login');

Route::get('/', $entry);
Route::get('/login', $entry)->name('login');

Route::get('/register', [RegistrationController::class, 'create']);
Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/two-factor/challenge', [AuthController::class, 'challengeForm']);
Route::post('/two-factor/challenge', [AuthController::class, 'challenge'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/forgot-password', [AuthController::class, 'forgotPasswordForm'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::get('/reset-password/{token}', [AuthController::class, 'resetPasswordForm'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('/account/two-factor', [TwoFactorController::class, 'show']);
    Route::get('/account/verify-email', [AuthController::class, 'verificationNotice']);
    Route::post('/account/verify-email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1');
    Route::post('/account/two-factor', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
    Route::get('/account/confirm-password', [TwoFactorController::class, 'confirmPasswordForm']);
    Route::post('/account/confirm-password', [TwoFactorController::class, 'confirmPassword'])->middleware('throttle:10,1');

    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware(['private.access', 'recent.auth', 'throttle:10,1']);
    Route::post('/account/two-factor/replace', [TwoFactorController::class, 'replace'])
        ->middleware(['private.access', 'recent.auth', 'throttle:10,1']);

    Route::get('/account/sessions', [SessionController::class, 'index'])
        ->middleware('private.access');
    Route::delete('/account/sessions/{id}', [SessionController::class, 'destroy'])
        ->middleware(['private.access', 'recent.auth']);
});

Route::middleware('private.access')->group(function () {
    Route::get('/workspace', [WorkspaceController::class, 'index'])
        ->middleware('creative');

    Route::get('/admin', [AdminController::class, 'index'])->middleware('admin.accounts');
    Route::get('/admin/accounts', [AdminController::class, 'accounts'])->middleware('admin.accounts');
    Route::get('/admin/audit', [AdminController::class, 'auditTrail'])->middleware('admin.accounts');
    Route::patch('/admin/accounts/{user}/quota', [AdminController::class, 'updateQuota'])
        ->middleware(['admin.accounts', 'recent.auth']);
    Route::patch('/admin/accounts/{user}/suspend', [AdminController::class, 'suspend'])
        ->middleware(['admin.accounts', 'recent.auth']);
    Route::patch('/admin/accounts/{user}/restore', [AdminController::class, 'restore'])
        ->middleware(['admin.accounts', 'recent.auth']);
});
