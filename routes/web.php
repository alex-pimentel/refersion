<?php

use App\Http\Controllers\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LeaderboardController::class, 'index'])
    ->name('leaderboard')
    ->middleware('throttle:60,1');

Route::post('/leaderboard/refresh', [LeaderboardController::class, 'refresh'])
    ->name('leaderboard.refresh')
    ->middleware('throttle:10,1');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ]);
})->name('health');
