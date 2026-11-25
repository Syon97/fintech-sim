<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
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

    Route::middleware('can:cases.view')->group(function () {
        Route::get('/cases', [CaseController::class, 'index']);
        Route::get('/cases/{case}', [CaseController::class, 'show'])->whereNumber('case');
    });

    Route::middleware('can:cases.manage')->group(function () {
        Route::get('/case-assignees', [CaseController::class, 'assignees']);
        Route::post('/groups/{group}/case', [CaseController::class, 'open'])->whereNumber('group');
        Route::post('/cases/{case}/notes', [CaseController::class, 'note'])->whereNumber('case');
        Route::post('/cases/{case}/assign', [CaseController::class, 'assign'])->whereNumber('case');
        Route::post('/cases/{case}/freeze', [CaseController::class, 'freeze'])->whereNumber('case');
        Route::post('/cases/{case}/unfreeze', [CaseController::class, 'unfreeze'])->whereNumber('case');
        Route::post('/cases/{case}/close', [CaseController::class, 'close'])->whereNumber('case');
    });
});