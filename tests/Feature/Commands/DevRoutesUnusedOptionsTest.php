<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Illuminate\Support\Facades\Route;

it('supports different detection modes', function () {
    // Routes API non protégées
    Route::prefix('api')->group(function () {
        Route::post('dangerous', 'ApiController@dangerous')->name('api.dangerous');
        Route::delete('delete-all', 'ApiController@deleteAll')->name('api.delete.all');
    });

    // Route debug non protégée
    Route::get('/debug/info', 'DebugController@info')->name('debug.info');

    // Route admin protégée
    Route::get('/admin/panel', 'AdminController@panel')
        ->middleware('auth')
        ->name('admin.panel');

    $scanner = new RouteScanner($this->app);

    // Test mode normal (devrait exclure les API par défaut)
    $normalResult = $scanner->scan([
        'detect_unused' => true,
    ]);
    $normalUnused = array_filter($normalResult['data']['routes'], fn ($r) => $r['unused'] ?? false);
    $normalNames = array_column($normalUnused, 'name');

    expect($normalNames)->toContain('debug.info') // Debug route détectée
        ->and($normalNames)->toContain('api.dangerous') // API dangerous détectée
        ->and($normalNames)->toContain('api.delete.all'); // API dangerous détectée

    // Test mode exclude-api (ne devrait pas avoir d'API)
    $excludeApiResult = $scanner->scan([
        'detect_unused' => true,
        'exclude_api_routes' => true,
    ]);
    $excludeApiUnused = array_filter($excludeApiResult['data']['routes'], fn ($r) => $r['unused'] ?? false);
    $excludeApiNames = array_column($excludeApiUnused, 'name');

    expect($excludeApiNames)->toContain('debug.info') // Debug route toujours détectée
        ->and($excludeApiNames)->not->toContain('api.dangerous') // API exclue
        ->and($excludeApiNames)->not->toContain('api.delete.all'); // API exclue

    // Test mode security-focused (devrait se concentrer sur les problèmes de sécurité)
    $securityResult = $scanner->scan([
        'detect_unused' => true,
        'security_focused' => true,
    ]);
    $securityUnused = array_filter($securityResult['data']['routes'], fn ($r) => $r['unused'] ?? false);
    $securityNames = array_column($securityUnused, 'name');

    expect($securityNames)->toContain('debug.info') // Route debug non protégée
        ->and($securityNames)->toContain('api.dangerous') // API non protégée
        ->and($securityNames)->not->toContain('admin.panel'); // Admin protégée, donc OK
});

it('can test command options', function () {
    // Route debug non protégée
    Route::get('/debug/info', 'DebugController@info')->name('debug.info');
    Route::post('/api/unprotected', 'ApiController@unprotected')->name('api.unprotected');

    // Test commande avec --exclude-api
    $this->artisan('dev:routes:unused --exclude-api')
        ->expectsOutput('Found 1 potentially unused/problematic routes:');

    // Test commande avec --security-focused
    $this->artisan('dev:routes:unused --security-focused')
        ->expectsOutput('Found 2 potentially unused/problematic routes:');
});
