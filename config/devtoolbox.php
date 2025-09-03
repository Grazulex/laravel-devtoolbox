<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Devtoolbox Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for Laravel Devtoolbox.
    | You can customize various aspects of the scanning and analysis tools.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Scanner Options
    |--------------------------------------------------------------------------
    |
    | These options will be used as defaults for all scanners unless
    | specifically overridden when calling the scan methods.
    |
    */
    'defaults' => [
        'format' => 'array', // array, json, count
        'include_metadata' => true,
        'exclude_paths' => [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'models' => [
        'paths' => [
            'app/Models',
        ],
        'include_relationships' => true,
        'include_attributes' => true,
        'include_scopes' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'group_by_middleware' => false,
        'include_parameters' => true,
        'detect_unused' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'commands' => [
        'custom_only' => false,
        'include_signatures' => true,
        'group_by_namespace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'services' => [
        'include_singletons' => true,
        'include_aliases' => true,
        'filter_custom' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | View Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'views' => [
        'view_paths' => [
            // Will default to resource_path('views') if empty
        ],
        'detect_unused' => false,
        'include_components' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'include_usage' => true,
        'group_by_type' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Scanner Configuration
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'memory_threshold' => 80, // Percentage threshold for memory warnings
        'slow_query_threshold' => 1000, // Milliseconds
        'include_opcache_analysis' => true,
        'include_redis_analysis' => true,
        'include_file_cache_analysis' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    */
    'output' => [
        'formats' => [
            'json' => [
                'pretty_print' => true,
                'escape_unicode' => false,
            ],
            'markdown' => [
                'include_toc' => true,
                'include_timestamps' => true,
            ],
            'mermaid' => [
                'direction' => 'TB', // TB, BT, RL, LR
                'theme' => 'default',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'memory_limit' => '512M',
        'time_limit' => 300, // seconds
        'chunk_size' => 100, // for large datasets
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    */
    'export' => [
        'default_path' => storage_path('devtoolbox'),
        'filename_format' => 'devtoolbox-{type}-{date}', // {type}, {date}, {time}
        'auto_timestamp' => true,
    ],
];
