<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Illuminate\Support\Facades\Route;

it('demonstrates improved unused route detection', function () {
    // Ce test démontre comment les améliorations permettent de détecter plus de routes problématiques

    // Routes qui seraient manquées par l'ancienne logique
    Route::get('/unused-route-1', function () {
        return 'This route is not used anywhere';
    })->name('unused.route.1');

    Route::get('/debug/info', 'DebugController@index')->name('debug.info');

    Route::post('/settings/profile', 'SettingsController@updateProfile')->name('settings.profile.update');

    Route::delete('/api/products/{id}', 'ProductController@destroy')->name('api.products.destroy');

    $scanner = new RouteScanner($this->app);
    $result = $scanner->scan(['detect_unused' => true]);

    $unusedRoutes = array_filter($result['data']['routes'], fn ($r) => $r['unused'] ?? false);
    $unusedNames = array_column($unusedRoutes, 'name');

    // Vérifier que toutes ces routes problématiques sont détectées
    expect($unusedNames)
        ->toContain('unused.route.1')     // Route avec pattern "unused"
        ->toContain('debug.info')         // Route debug non protégée
        ->toContain('settings.profile.update') // Route settings non protégée avec méthode dangereuse
        ->toContain('api.products.destroy');   // Route API DELETE non protégée

    // Vérifier que nous détectons plus de routes qu'avant
    expect(count($unusedRoutes))->toBeGreaterThan(3);

    // Vérifications supplémentaires pour s'assurer que l'amélioration fonctionne
    expect($unusedRoutes)->toHaveCount(4);
});
