<?php

declare(strict_types=1);

it('reports the mcp server status in json output', function (): void {
    Illuminate\Support\Facades\Artisan::call('dev:about+', ['--format' => 'json']);
    $json = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($json['mcp'])->toMatchArray([
        'installed' => class_exists(Laravel\Mcp\Facades\Mcp::class),
        'enabled' => true,
        'environment_allowed' => true,
        'server' => 'devtoolbox',
        'start_command' => 'php artisan mcp:start devtoolbox',
    ]);
});
