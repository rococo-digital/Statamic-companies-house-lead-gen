<?php

use Illuminate\Support\Facades\Route;
use Rococo\ChLeadGen\Http\Controllers\CP\DashboardController;
use Rococo\ChLeadGen\Http\Controllers\CP\SettingsController;

Route::name('ch-lead-gen.')->prefix('ch-lead-gen')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::post('/run', [DashboardController::class, 'run'])->name('run');
    
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
    
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
}); 