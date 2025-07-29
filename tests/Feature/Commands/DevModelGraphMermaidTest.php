<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Console\Commands\DevModelGraphCommand;

it('generates mermaid format correctly', function () {
    // Mock model data that would be returned by the scanner
    $mockModelData = [
        'data' => [
            [
                'name' => 'App\\Models\\User',
                'relationships' => [
                    'posts' => [
                        'type' => 'hasMany',
                        'related' => 'App\\Models\\Post',
                    ],
                    'role' => [
                        'type' => 'belongsTo',
                        'related' => 'App\\Models\\Role',
                    ],
                ],
            ],
            [
                'name' => 'App\\Models\\Post',
                'relationships' => [
                    'user' => [
                        'type' => 'belongsTo',
                        'related' => 'App\\Models\\User',
                    ],
                    'comments' => [
                        'type' => 'hasMany',
                        'related' => 'App\\Models\\Comment',
                    ],
                ],
            ],
            [
                'name' => 'App\\Models\\Role',
                'relationships' => [
                    'users' => [
                        'type' => 'hasMany',
                        'related' => 'App\\Models\\User',
                    ],
                ],
            ],
        ],
    ];

    // Create command instance
    $command = new DevModelGraphCommand();

    // Use reflection to call the private method
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('generateMermaidGraph');
    $method->setAccessible(true);

    $result = $method->invoke($command, $mockModelData, 'TB');

    expect($result)->toBeString()
        ->and($result)->toContain('graph TB')
        ->and($result)->toContain('User[App\\Models\\User]')
        ->and($result)->toContain('Post[App\\Models\\Post]')
        ->and($result)->toContain('Role[App\\Models\\Role]')
        ->and($result)->toContain('||--o{') // hasMany relationship
        ->and($result)->toContain('}o--||') // belongsTo relationship
        ->and($result)->toContain('posts')
        ->and($result)->toContain('user')
        ->and($result)->toContain('role');
});

it('supports different graph directions', function () {
    $mockModelData = [
        'data' => [
            [
                'name' => 'App\\Models\\User',
                'relationships' => [
                    'posts' => [
                        'type' => 'hasMany',
                        'related' => 'App\\Models\\Post',
                    ],
                ],
            ],
        ],
    ];

    $command = new DevModelGraphCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('generateMermaidGraph');
    $method->setAccessible(true);

    $resultTB = $method->invoke($command, $mockModelData, 'TB');
    $resultLR = $method->invoke($command, $mockModelData, 'LR');

    expect($resultTB)->toContain('graph TB')
        ->and($resultLR)->toContain('graph LR');
});

it('sanitizes node names correctly', function () {
    $command = new DevModelGraphCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('sanitizeNodeName');
    $method->setAccessible(true);

    expect($method->invoke($command, 'App\\Models\\User'))->toBe('User')
        ->and($method->invoke($command, 'App\\Models\\SomeModel-With_Special.Chars'))->toBe('SomeModel_With_Special_Chars');
});
