<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Illuminate\Support\Facades\Route;

it('detects unused routes correctly', function () {
    // Define test routes
    Route::get('/legacy/old-feature', function () {
        return 'This route is never used';
    })->name('legacy.old-feature');

    Route::get('/maintenance', function () {
        return 'Under maintenance';
    })->name('maintenance');

    Route::delete('/dangerous-action', function () {
        return 'This should be protected!';
    })->name('dangerous.action');

    Route::get('/test/demo', function () {
        return 'Test route';
    }); // No name, closure

    Route::get('/sample/unused', function () {
        return 'Sample unused route';
    });

    // Normal routes that should NOT be detected as unused
    Route::get('/', function () {
        return view('welcome');
    })->name('home');

    Route::get('/api/users', function () {
        return ['users' => []];
    })->name('api.users');

    $scanner = new RouteScanner($this->app);
    $result = $scanner->scan(['detect_unused' => true]);

    expect($result)->toBeArray()
        ->toHaveKey('data')
        ->and($result['data'])->toHaveKeys(['routes', 'unused_routes']);

    // Check that some routes are marked as unused
    $routes = $result['data']['routes'];
    $unusedRoutes = array_filter($routes, fn ($route) => $route['unused'] ?? false);

    expect($unusedRoutes)->not->toBeEmpty();

    // Check specific routes that should be detected as unused
    $unusedRouteUris = array_column($unusedRoutes, 'uri');

    expect($unusedRouteUris)->toContain('legacy/old-feature')
        ->and($unusedRouteUris)->toContain('maintenance')
        ->and($unusedRouteUris)->toContain('dangerous-action')
        ->and($unusedRouteUris)->toContain('test/demo')
        ->and($unusedRouteUris)->toContain('sample/unused');

    // API routes should NOT be marked as unused
    $homeRoute = collect($routes)->firstWhere('name', 'home');
    $apiRoute = collect($routes)->firstWhere('name', 'api.users');

    expect($homeRoute['unused'] ?? false)->toBeFalse()
        ->and($apiRoute['unused'] ?? false)->toBeFalse();
});
