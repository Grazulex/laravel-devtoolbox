<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Illuminate\Support\Facades\Route;

it('can detect unused routes from real world example', function () {
    // Simuler le fichier de routes fourni par l'utilisateur

    // Routes API (ne devraient pas être marquées comme inutilisées par défaut)
    Route::prefix('api')->group(function () {
        Route::apiResource('products', 'ProductController');
        Route::get('products/featured', 'ProductController@featured');
        Route::get('products/search', 'ProductController@search');
        Route::apiResource('categories', 'CategoryController');
        Route::apiResource('posts', 'PostController');
        Route::apiResource('orders', 'OrderController');
        Route::apiResource('comments', 'CommentController');
    });

    // Routes admin (certaines non utilisées)
    Route::prefix('admin')->middleware(['auth'])->group(function () {
        Route::get('/dashboard', function () {
            return view('admin.dashboard');
        })->name('admin.dashboard');

        Route::get('/analytics', function () {
            return view('admin.analytics');
        })->name('admin.analytics');

        Route::get('/settings', function () {
            return view('admin.settings');
        })->name('admin.settings');

        // Route non utilisée pour démo
        Route::get('/reports', function () {
            return view('admin.reports');
        })->name('admin.reports');
    });

    // Routes vraiment non utilisées
    Route::get('/unused-route-1', function () {
        return 'This route is not used anywhere';
    })->name('unused.route.1');

    Route::get('/unused-route-2', function () {
        return 'Another unused route';
    })->name('unused.route.2');

    // Routes de debug non protégées (devraient être détectées)
    Route::get('/debug/info', 'DebugController@index')->name('debug.info');
    Route::get('/debug/users', 'DebugController@searchUsers')->name('debug.users');
    Route::get('/debug/file', 'DebugController@viewFile')->name('debug.file');
    Route::get('/debug/database', 'DebugController@dumpDatabase')->name('debug.database');
    Route::post('/debug/session', 'DebugController@manipulateSession')->name('debug.session');
    Route::post('/debug/eval', 'DebugController@evalCode')->name('debug.eval');

    // Routes settings non protégées (problème de sécurité)
    Route::get('/settings', 'SettingsController@index')->name('settings.index');
    Route::get('/settings/profile', 'SettingsController@profile')->name('settings.profile');
    Route::post('/settings/profile', 'SettingsController@updateProfile')->name('settings.profile.update');
    Route::get('/settings/notifications', 'SettingsController@notifications')->name('settings.notifications');
    Route::post('/settings/notifications', 'SettingsController@updateNotifications')->name('settings.notifications.update');
    Route::get('/settings/privacy', 'SettingsController@privacy')->name('settings.privacy');
    Route::post('/settings/privacy', 'SettingsController@updatePrivacy')->name('settings.privacy.update');

    // Plus de routes non utilisées
    Route::get('/dashboard', function () {
        return 'Dashboard page - not used';
    })->name('dashboard');

    Route::get('/profile/{user}', function ($user) {
        return "Profile for user: $user - not used";
    })->name('profile.show');

    Route::post('/contact', function () {
        return 'Contact form submission - not implemented';
    })->name('contact.submit');

    Route::get('/legacy-endpoint', function () {
        return 'Legacy endpoint that should be removed';
    })->name('legacy.endpoint');

    $scanner = new RouteScanner($this->app);
    $result = $scanner->scan(['detect_unused' => true]);

    expect($result)->toBeArray()
        ->toHaveKey('data')
        ->and($result['data'])->toHaveKeys(['routes', 'unused_routes']);

    $routes = $result['data']['routes'];
    $unusedRoutes = array_filter($routes, fn ($route) => $route['unused'] ?? false);

    // Vérifier que certaines routes sont bien détectées comme non utilisées
    $unusedRouteNames = array_column($unusedRoutes, 'name');

    // Ces routes devraient être détectées comme non utilisées
    expect($unusedRouteNames)->toContain('unused.route.1')
        ->and($unusedRouteNames)->toContain('unused.route.2')
        ->and($unusedRouteNames)->toContain('legacy.endpoint');

    // Les routes de debug devraient aussi être détectées (routes dangereuses sans protection)
    expect($unusedRouteNames)->toContain('debug.info')
        ->and($unusedRouteNames)->toContain('debug.users')
        ->and($unusedRouteNames)->toContain('debug.file');

    // Afficher les résultats pour debug
    $this->artisan('dev:routes:unused')
        ->expectsOutput('Found '.count($unusedRoutes).' potentially unused/problematic routes:');
});
