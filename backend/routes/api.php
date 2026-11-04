<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('can:alerts.view')->group(function () {
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::get('/alerts/{alert}', [AlertController::class, 'show'])->whereNumber('alert');
        Route::get('/groups', [AlertController::class, 'groups']);
    });

    Route::middleware('can:alerts.work')->group(function () {
        Route::post('/alerts/{alert}/start', [AlertController::class, 'start'])->whereNumber('alert');
        Route::post('/alerts/{alert}/release', [AlertController::class, 'release'])->whereNumber('alert');
        Route::post('/alerts/{alert}/close', [AlertController::class, 'close'])->whereNumber('alert');
    });

    Route::middleware('can:alerts.reassign')->group(function () {
        Route::post('/alerts/{alert}/reassign', [AlertController::class, 'reassign'])->whereNumber('alert');
        Route::get('/assignees', [AlertController::class, 'assignees']);
    });
});