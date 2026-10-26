<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

/*
 * The SPA is served from the same origin, so the API uses the `web` stack
 * (session cookie + CSRF protection) rather than tokens.
 */
Route::prefix('v1')->name('api.')->middleware('web')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth')->group(function () {
        Route::get('user', [AuthController::class, 'show'])->name('user');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::apiResource('documents', DocumentController::class)->except(['store', 'update']);
        Route::post('documents', [DocumentController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('documents.store');
        Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->name('documents.reprocess');

        Route::post('chat', ChatController::class)
            ->middleware('throttle:questions')
            ->name('chat');
    });
});
