<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Support\Facades\File;

function envCallsFixture(): string
{
    $dir = sys_get_temp_dir().'/devtoolbox-env-calls-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($dir.'/app/Services');
    File::ensureDirectoryExists($dir.'/resources/views');
    File::ensureDirectoryExists($dir.'/config');
    File::ensureDirectoryExists($dir.'/legacy');

    File::put($dir.'/app/Services/Billing.php', implode("\n", [
        '<?php',
        'namespace App\Services;',
        'final class Billing {',
        '    public function key(): ?string { return env(\'STRIPE_KEY\'); }',
        '    public function secret(): ?string { return \env("STRIPE_SECRET", \'x\'); }',
        '    public function dynamic(string $name): mixed { return env($name); }',
        '    public function notHelper(): mixed { return $this->env(\'IGNORED\') ?? Config::env(\'IGNORED\'); }',
        '    public function env(string $key): mixed { return null; }',
        '    // env(\'IN_COMMENT\')',
        '    public string $text = "env(\'IN_STRING\')";',
        '}',
        '',
    ]));

    File::put($dir.'/resources/views/welcome.blade.php', implode("\n", [
        '<h1>{{ env(\'APP_NAME\') }}</h1>',
        '<p>{{ $settings->env(\'NOPE\') }}</p>',
        '',
    ]));

    File::put($dir.'/config/services.php', "<?php\n\nreturn ['key' => env('IN_CONFIG')];\n");
    File::put($dir.'/legacy/old.php', "<?php\n\nenv('EXCLUDED');\n");
    File::put($dir.'/app/readme.txt', "env('NOT_PHP')\n");

    return $dir;
}

beforeEach(function (): void {
    $this->envDir = envCallsFixture();
    $this->app->useConfigPath($this->envDir.'/config');
});

afterEach(function (): void {
    File::deleteDirectory($this->envDir);
});

it('is registered as a read-only scanner', function (): void {
    $scanner = app(DevtoolboxManager::class)->registry()->get('env-calls');

    expect($scanner->getName())->toBe('env-calls')
        ->and($scanner->getDescription())->toContain('env()')
        ->and(array_keys($scanner->getOptionSchema()))->toBe(['scan_paths', 'exclude_paths']);
});

it('finds env() helper calls outside config/ only', function (): void {
    $result = app(DevtoolboxManager::class)->scan('env-calls', [
        'scan_paths' => [$this->envDir],
        'exclude_paths' => [$this->envDir.'/legacy'],
    ]);

    $calls = $result['data']['calls'];

    expect($result['type'])->toBe('env-calls')
        ->and($result['metadata']['count'])->toBe(4)
        ->and(array_map(fn (array $call): array => [basename($call['file']), $call['line'], $call['key']], $calls))
        ->toBe([
            ['Billing.php', 4, 'STRIPE_KEY'],
            ['Billing.php', 5, 'STRIPE_SECRET'],
            ['Billing.php', 6, null],
            ['welcome.blade.php', 1, 'APP_NAME'],
        ])
        ->and($calls[0]['code'])->toBe('public function key(): ?string { return env(\'STRIPE_KEY\'); }')
        ->and($result['data']['summary'])->toBe([
            'total_calls' => 4,
            'files_with_calls' => 2,
            'scanned_files' => 2,
            'keys' => ['APP_NAME', 'STRIPE_KEY', 'STRIPE_SECRET'],
        ]);
});

it('never reports files inside the config directory', function (): void {
    $result = app(DevtoolboxManager::class)->scan('env-calls', ['scan_paths' => [$this->envDir.'/config']]);

    expect($result['data']['calls'])->toBe([])
        ->and($result['data']['summary']['scanned_files'])->toBe(0);
});

it('accepts single files and reports excluded paths as skipped', function (): void {
    $manager = app(DevtoolboxManager::class);

    $single = $manager->scan('env-calls', ['scan_paths' => [$this->envDir.'/legacy/old.php']]);
    $excluded = $manager->scan('env-calls', [
        'scan_paths' => [$this->envDir.'/legacy/old.php'],
        'exclude_paths' => [$this->envDir.'/legacy'],
    ]);

    expect(array_column($single['data']['calls'], 'key'))->toBe(['EXCLUDED'])
        ->and($excluded['data']['calls'])->toBe([]);
});

it('scans the default application paths when no path is given', function (): void {
    $result = app(DevtoolboxManager::class)->scan('env-calls');

    expect($result['data'])->toHaveKeys(['calls', 'summary'])
        ->and($result['data']['summary'])->toHaveKeys(['total_calls', 'files_with_calls', 'scanned_files', 'keys']);
});

it('prints the calls from the artisan command', function (): void {
    $this->artisan('dev:config:env-calls', ['--path' => [$this->envDir.'/app']])
        ->expectsOutputToContain('STRIPE_KEY')
        ->expectsOutputToContain('3 env() call(s) in 1 file(s) outside config/')
        ->assertExitCode(0);
});

it('outputs json from the artisan command', function (): void {
    $file = $this->envDir.'/result.json';

    $this->artisan('dev:config:env-calls', ['--path' => [$this->envDir.'/resources'], '--format' => 'json', '--output' => $file])
        ->assertExitCode(0);

    $json = json_decode((string) file_get_contents($file), true);

    expect($json['type'])->toBe('env-calls')
        ->and(array_column($json['data']['calls'], 'key'))->toBe(['APP_NAME']);
});

it('fails with --fail-on-found only when calls are found', function (): void {
    $this->artisan('dev:config:env-calls', ['--path' => [$this->envDir.'/app'], '--fail-on-found' => true])
        ->assertExitCode(1);

    $this->artisan('dev:config:env-calls', ['--path' => [$this->envDir.'/config'], '--fail-on-found' => true])
        ->expectsOutputToContain('No env() call found outside config/')
        ->assertExitCode(0);
});
