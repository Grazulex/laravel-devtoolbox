<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Illuminate\Contracts\Foundation\Application;

/**
 * Decides whether the DevToolbox MCP server may be registered. Pure, so the
 * service provider stays trivial and the rule is unit-testable.
 */
final class McpRegistration
{
    public static function shouldRegister(Application $app, ?bool $mcpInstalled = null): bool
    {
        $mcpInstalled ??= class_exists(\Laravel\Mcp\Facades\Mcp::class);

        return $mcpInstalled
            && (bool) $app['config']->get('devtoolbox.mcp.enabled', true)
            && self::isEnvironmentAllowed($app);
    }

    public static function isEnvironmentAllowed(Application $app): bool
    {
        /** @var list<string> $environments */
        $environments = $app['config']->get('devtoolbox.mcp.environments', ['local', 'testing']);

        return $app->environment($environments);
    }
}
