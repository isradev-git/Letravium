<?php

use App\Http\Middleware\EnsureAccountAdmin;
use App\Http\Middleware\EnsureCommunicationsAdmin;
use App\Http\Middleware\EnsureCreativeAccess;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsurePrivateAccess;
use App\Http\Middleware\EnsureRecentAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            foreach (['marketing', 'workspace', 'assets', 'editor', 'collaboration', 'history', 'snapshots', 'sync', 'book', 'imports', 'universe'] as $module) {
                $path = base_path("routes/{$module}.php");
                if (file_exists($path)) {
                    Route::middleware('web')->group($path);
                }
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            'content.*',
            'blocks.*',
            'scenes.*',
            'chapters.*',
        ]);
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->exists('content')
                || $request->exists('blocks')
                || $request->exists('scenes')
                || $request->exists('chapters'),
        ]);

        $middleware->alias([
            'private.access' => EnsurePrivateAccess::class,
            'creative' => EnsureCreativeAccess::class,
            'admin' => EnsurePlatformAdmin::class,
            'admin.accounts' => EnsureAccountAdmin::class,
            'admin.communications' => EnsureCommunicationsAdmin::class,
            'recent.auth' => EnsureRecentAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
