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
