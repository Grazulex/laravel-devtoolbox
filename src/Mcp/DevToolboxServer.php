<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Composer\InstalledVersions;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;
use OutOfBoundsException;

#[Name('DevToolbox')]
#[Instructions('Read-only introspection of this Laravel application: routes, models, middleware, container bindings, views, providers, security checks. The sql-trace, sql-analysis and performance tools actually execute an internal request against the application; use them only on local environments.')]
final class DevToolboxServer extends Server
{
    protected string $version;

    /** @var list<ScannerTool> */
    private array $scannerTools;

    public function __construct(
        Transport $transport,
        ToolFactory $factory,
        DevtoolboxManager $manager,
        private readonly Application $app,
    ) {
        parent::__construct($transport);

        $this->version = self::packageVersion();
        $this->scannerTools = $factory->make($manager->registry());
        $this->tools = $this->scannerTools;
    }

    protected function boot(): void
    {
        if (McpRegistration::isEnvironmentAllowed($this->app)) {
            return;
        }

        foreach ($this->scannerTools as $tool) {
            $tool->disable('DevToolbox MCP is disabled in this environment.');
        }
    }

    private static function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('grazulex/laravel-devtoolbox') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
