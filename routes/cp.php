<?php

use Illuminate\Support\Facades\Route;
use Rococo\ChLeadGen\Http\Controllers\CP\DashboardController;
use Rococo\ChLeadGen\Http\Controllers\CP\SettingsController;
use Rococo\ChLeadGen\Http\Controllers\CP\RulesController;
use Rococo\ChLeadGen\Http\Controllers\CP\ApolloStatsController;

Route::name('ch-lead-gen.')->prefix('ch-lead-gen')->middleware('can:view ch-lead-gen')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/run', [DashboardController::class, 'run'])->name('run');
    
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
    
    Route::post('/toggle-rule/{ruleKey}', [DashboardController::class, 'toggleRule'])->middleware('can:edit ch-lead-gen')->name('toggle-rule');
    
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('can:edit ch-lead-gen')->name('settings.update');
    
    Route::get('/apollo-stats', [ApolloStatsController::class, 'index'])->name('apollo-stats');
    
    // Rules management routes
    Route::prefix('rules')->name('rules.')->group(function () {
        Route::get('/', [RulesController::class, 'index'])->name('index');
        Route::get('/create', [RulesController::class, 'create'])->middleware('can:create ch-lead-gen')->name('create');
        Route::post('/', [RulesController::class, 'store'])->middleware('can:create ch-lead-gen')->name('store');
        Route::get('/{ruleKey}/edit', [RulesController::class, 'edit'])->middleware('can:edit ch-lead-gen')->name('edit');
        Route::put('/{ruleKey}', [RulesController::class, 'update'])->middleware('can:edit ch-lead-gen')->name('update');
        Route::delete('/{ruleKey}', [RulesController::class, 'destroy'])->middleware('can:delete ch-lead-gen')->name('destroy');
        Route::post('/{ruleKey}/duplicate', [RulesController::class, 'duplicate'])->middleware('can:create ch-lead-gen')->name('duplicate');
        Route::post('/{ruleKey}/test-webhook', [RulesController::class, 'testWebhook'])->middleware('can:edit ch-lead-gen')->name('test-webhook');
    });
}); 