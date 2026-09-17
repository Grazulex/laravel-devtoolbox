<?php

declare(strict_types=1);

it('ships default mcp configuration', function (): void {
    expect(config('devtoolbox.mcp.enabled'))->toBeTrue()
        ->and(config('devtoolbox.mcp.environments'))->toBe(['local', 'testing'])
        ->and(config('devtoolbox.mcp.max_response_bytes'))->toBe(262_144);
});
