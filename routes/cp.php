<?php

use Illuminate\Support\Facades\Route;
use Rococo\ChLeadGen\Http\Controllers\CP\DashboardController;
use Rococo\ChLeadGen\Http\Controllers\CP\SettingsController;
use Rococo\ChLeadGen\Http\Controllers\CP\RulesController;
use Rococo\ChLeadGen\Http\Controllers\CP\ApolloStatsController;

Route::name('ch-lead-gen.')->prefix('ch-lead-gen')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/run', [DashboardController::class, 'run'])->name('run');
    
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
    
    Route::post('/toggle-rule/{ruleKey}', [DashboardController::class, 'toggleRule'])->name('toggle-rule');
    
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    
    Route::get('/apollo-stats', [ApolloStatsController::class, 'index'])->name('apollo-stats');
    
    // Rules management routes
    Route::prefix('rules')->name('rules.')->group(function () {
        Route::get('/', [RulesController::class, 'index'])->name('index');
        Route::get('/create', [RulesController::class, 'create'])->name('create');
        Route::post('/', [RulesController::class, 'store'])->name('store');
        Route::get('/{ruleKey}/edit', [RulesController::class, 'edit'])->name('edit');
        Route::put('/{ruleKey}', [RulesController::class, 'update'])->name('update');
        Route::delete('/{ruleKey}', [RulesController::class, 'destroy'])->name('destroy');
        Route::post('/{ruleKey}/duplicate', [RulesController::class, 'duplicate'])->name('duplicate');
        Route::post('/{ruleKey}/test-webhook', [RulesController::class, 'testWebhook'])->name('test-webhook');
    });
}); 