<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;

function bigResult(int $items): array
{
    return [
        'count' => $items,
        'scanner' => 'routes',
        'routes' => array_map(fn (int $i): array => ['uri' => "/path/$i", 'name' => str_repeat('é', 40).$i], range(1, $items)),
        'grouped_by_middleware' => ['web' => range(1, $items)],
    ];
}

it('returns the result untouched when it fits', function (): void {
    $result = bigResult(3);

    expect((new ResponseTruncator(1_000_000))->truncate($result, []))->toBe($result);
});

it('truncates the first list, drops other big values and annotates', function (): void {
    $maxBytes = 2_000;
    $truncated = (new ResponseTruncator($maxBytes))->truncate(bigResult(500), ['detect_unused' => ['type' => 'boolean', 'description' => 'x'], 'filter_methods' => ['type' => 'array', 'description' => 'y']]);

    expect(mb_strlen(json_encode($truncated, JSON_UNESCAPED_UNICODE), '8bit'))->toBeLessThanOrEqual($maxBytes)
        ->and($truncated['count'])->toBe(500)
        ->and($truncated['scanner'])->toBe('routes')
        ->and($truncated['routes'])->toBeArray()->not->toBeEmpty()
        ->and(count($truncated['routes']))->toBeLessThan(500)
        ->and($truncated['grouped_by_middleware'])->toBeNull()
        ->and($truncated['_truncated'])->toBe([
            'original_items' => 500,
            'kept_items' => count($truncated['routes']),
            'path' => 'routes',
            'hint' => 'Refine with options: detect_unused, filter_methods',
        ]);
});

it('keeps zero items when even one does not fit, and stays valid JSON', function (): void {
    $truncated = (new ResponseTruncator(120))->truncate(bigResult(50), []);

    expect(json_encode($truncated))->toBeString()
        ->and($truncated['_truncated']['kept_items'])->toBe(0)
        ->and($truncated['_truncated']['hint'])->toBe('No options available to refine this scan');
});

it('measures the budget in bytes, not characters', function (): void {
    $result = ['count' => 1, 'items' => [str_repeat('é', 100)]]; // 200 bytes of payload, 100 chars
    $truncated = (new ResponseTruncator(150))->truncate($result, []);

    expect(mb_strlen(json_encode($truncated, JSON_UNESCAPED_UNICODE), '8bit'))->toBeLessThanOrEqual(150)
        ->and($truncated['_truncated']['kept_items'])->toBe(0);
});

function envelope(array $data): array
{
    return [
        'metadata' => ['scanner' => 'routes', 'description' => 'Scan Laravel routes', 'scanned_at' => '2026-09-17T10:00:00.000000Z', 'count' => 2],
        'data' => $data,
    ];
}

function encodedSize(array $value): int
{
    return mb_strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), '8bit');
}

it('truncates the list nested in a scanner envelope and keeps metadata and sibling scalars', function (): void {
    $routes = array_map(fn (int $i): array => ['uri' => "/path/$i", 'name' => str_repeat('r', 60).$i, 'methods' => ['GET', 'HEAD']], range(1, 300));
    $result = envelope(['routes' => $routes, 'count' => 300, 'unused_routes' => array_slice($routes, 0, 50)]);
    $maxBytes = 5_000;

    $truncated = (new ResponseTruncator($maxBytes))->truncate($result, ['detect_unused' => ['type' => 'boolean', 'description' => 'x']]);

    expect(encodedSize($truncated))->toBeLessThanOrEqual($maxBytes)
        ->and($truncated['metadata'])->toBe($result['metadata'])
        ->and($truncated['data']['count'])->toBe(300)
        ->and($truncated['data']['routes'])->toBeArray()->not->toBeEmpty()
        ->and(count($truncated['data']['routes']))->toBeLessThan(300)
        ->and($truncated['data']['routes'])->toBe(array_slice($routes, 0, count($truncated['data']['routes'])))
        ->and($truncated['data']['unused_routes'])->toBeNull()
        ->and($truncated['_truncated'])->toBe([
            'original_items' => 300,
            'kept_items' => count($truncated['data']['routes']),
            'path' => 'data.routes',
            'hint' => 'Refine with options: detect_unused',
        ]);
});

it('truncates an associative map keyed by name, preserving keys', function (): void {
    $columnUsage = [];
    foreach (range(1, 80) as $t) {
        $columnUsage["table_$t"] = [
            'id' => ['used' => true, 'usage_count' => 3, 'files' => [['path' => str_repeat('p', 40)]], 'recommendations' => []],
            'name' => ['used' => false, 'usage_count' => 0, 'files' => [], 'recommendations' => ['drop it']],
        ];
    }
    $result = envelope(['column_usage' => $columnUsage, 'summary' => ['total_columns' => 160, 'tables_summary' => array_fill_keys(array_keys($columnUsage), ['total' => 2])]]);
    $maxBytes = 4_000;

    $truncated = (new ResponseTruncator($maxBytes))->truncate($result, []);

    $keptTables = array_keys($truncated['data']['column_usage']);

    expect(encodedSize($truncated))->toBeLessThanOrEqual($maxBytes)
        ->and($truncated['metadata'])->toBe($result['metadata'])
        ->and($keptTables)->not->toBeEmpty()
        ->and(count($keptTables))->toBeLessThan(80)
        ->and($keptTables)->toBe(array_slice(array_keys($columnUsage), 0, count($keptTables)))
        ->and($truncated['data']['column_usage'])->toBe(array_slice($columnUsage, 0, count($keptTables), true))
        ->and($truncated['data']['summary'])->toBeNull()
        ->and($truncated['_truncated']['original_items'])->toBe(80)
        ->and($truncated['_truncated']['kept_items'])->toBe(count($keptTables))
        ->and($truncated['_truncated']['path'])->toBe('data.column_usage');
});

it('truncates a top-level keyed map without an envelope', function (): void {
    $result = [];
    foreach (range(1, 100) as $i) {
        $result["App\\Service$i"] = ['singleton' => true, 'concrete' => str_repeat('c', 50)];
    }

    $truncated = (new ResponseTruncator(2_000))->truncate($result, []);

    $kept = array_diff_key($truncated, ['_truncated' => true]);

    expect(encodedSize($truncated))->toBeLessThanOrEqual(2_000)
        ->and($kept)->not->toBeEmpty()
        ->and($kept)->toBe(array_slice($result, 0, count($kept), true))
        ->and($truncated['_truncated']['original_items'])->toBe(100)
        ->and($truncated['_truncated']['kept_items'])->toBe(count($kept))
        ->and($truncated['_truncated']['path'])->toBe('(root)');
});

it('tolerates invalid UTF-8 when measuring the budget', function (): void {
    $result = ['count' => 1, 'items' => array_fill(0, 50, ['binding' => "\xB1\x31".str_repeat('x', 100)])];

    $truncated = (new ResponseTruncator(1_500))->truncate($result, []);

    expect($truncated['_truncated']['kept_items'])->toBeGreaterThan(0)
        ->and(count($truncated['items']))->toBeLessThan(50);
});
