<?php

use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApiInfoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bantay Cordova API
|--------------------------------------------------------------------------
|
| React talks to this API with cookie-based Sanctum SPA authentication.
| Scripts and integrations use personal access tokens (abilities).
|
*/

// Availability: public uptime check, intentionally not rate limited.
Route::get('/health', [ApiInfoController::class, 'health']);

Route::middleware('throttle:api')->group(function () {
    // ---------------------------------------------------------------------
    // Authentication (public)
    // ---------------------------------------------------------------------
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    // ---------------------------------------------------------------------
    // Public news
    // ---------------------------------------------------------------------
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/news/{news}', [NewsController::class, 'show']);

    // ---------------------------------------------------------------------
    // Authenticated
    // ---------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'must-change-password'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // AI assistant: 20 requests/minute per user plus a daily token budget.
        Route::post('/chat', [ChatController::class, 'send'])->middleware('throttle:chat');

        // News write access (editor or admin) — least privilege via token abilities.
        Route::middleware('role:editor,admin')->group(function () {
            Route::post('/news', [NewsController::class, 'store'])->middleware('ability:news:write');
            Route::put('/news/{news}', [NewsController::class, 'update'])->middleware('ability:news:write');
            Route::delete('/news/{news}', [NewsController::class, 'destroy'])->middleware('ability:news:write');
        });

        // Editors and admins may inspect revision history of stories they own.
        Route::get('/news/{news}/revisions', [NewsController::class, 'revisions'])->middleware('role:editor,admin');

        // -----------------------------------------------------------------
        // Administration (admin only)
        // -----------------------------------------------------------------
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/overview', [DashboardController::class, 'index']);

            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{user}', [UserController::class, 'update']);

            Route::get('/tokens', [TokenController::class, 'index']);
            Route::post('/tokens', [TokenController::class, 'store']);
            Route::delete('/tokens/{token}', [TokenController::class, 'destroy']);

            Route::get('/audit', [AuditController::class, 'index']);
            Route::get('/audit/export', [AuditController::class, 'export']);
            Route::post('/audit/verify', [AuditController::class, 'verify']);

            Route::get('/ai-usage', [AiUsageController::class, 'index']);

            Route::get('/security', [SecurityController::class, 'index']);

            Route::get('/content/revisions', [ContentController::class, 'index']);
            Route::get('/content/revisions/raw', [ContentController::class, 'revisions']);
            Route::get('/content/deleted', [ContentController::class, 'trashed']);

            Route::get('/settings', [SettingController::class, 'index']);
            Route::patch('/settings', [SettingController::class, 'update']);
        });

        // Restoring a soft-deleted story is an admin-only recovery action.
        Route::post('/news/{news}/restore', [NewsController::class, 'restore'])->middleware('role:admin');
    });
});
