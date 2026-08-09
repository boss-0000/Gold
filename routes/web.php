<?php

declare(strict_types=1);

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DemoController::class, 'index'])->name('demo.index');

// Disparar una corrida: rate-limit para evitar abuso desde un visitante hostil.
Route::post('/demo/run', [DemoController::class, 'run'])
    ->middleware('throttle:20,1')
    ->name('demo.run');

// Polling del estado de una corrida.
Route::get('/demo/run/{run}', [DemoController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('demo.status');
