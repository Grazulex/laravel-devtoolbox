<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Grazulex\LaravelDevtoolbox\Scanners\SecurityScanner;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class DevSecurityThrottleCommandTest extends TestCase
{
    public function test_command_runs_with_table_and_json_formats(): void
    {
        $this->artisan('dev:security:throttle')->assertExitCode(0);
        $this->artisan('dev:security:throttle --format=json')->assertExitCode(0);
    }

    public function test_scanner_flags_sensitive_routes_without_throttle(): void
    {
        Route::post('/login', fn () => 'ok');
        Route::post('/register', fn () => 'ok')->middleware('throttle:6,1');
        Route::get('/api/users', fn () => 'ok');
        Route::get('/about', fn () => 'ok');

        $result = (new SecurityScanner($this->app))->scan([
            'check_unprotected_routes' => false,
            'check_csrf_protection' => false,
            'check_throttle' => true,
        ]);

        $uris = array_column($result['data']['unthrottled_routes'], 'uri');

        $this->assertContains('login', $uris);
        $this->assertContains('api/users', $uris);
        $this->assertNotContains('register', $uris);
        $this->assertNotContains('about', $uris);
    }

    public function test_throttle_check_is_disabled_by_default(): void
    {
        $result = (new SecurityScanner($this->app))->scan();

        $this->assertArrayNotHasKey('unthrottled_routes', $result['data']);
    }
}
