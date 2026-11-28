<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Support\Facades\File;

function langMissingFixture(): string
{
    $dir = sys_get_temp_dir().'/devtoolbox-lang-missing-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($dir.'/app/Http');
    File::ensureDirectoryExists($dir.'/resources/views');
    File::ensureDirectoryExists($dir.'/legacy');
    File::ensureDirectoryExists($dir.'/lang/en');
    File::ensureDirectoryExists($dir.'/lang/fr');
    File::ensureDirectoryExists($dir.'/lang/vendor/package/en');
    File::ensureDirectoryExists($dir.'/empty-lang');

    File::put($dir.'/lang/en/messages.php', "<?php\n\nreturn ['welcome' => 'Welcome', 'nested' => ['title' => 'Title'], 'only_en' => 'Only here'];\n");
    File::put($dir.'/lang/fr/messages.php', "<?php\n\nreturn ['welcome' => 'Bienvenue'];\n");
    File::put($dir.'/lang/en.json', (string) json_encode(['Save' => 'Save', 'Hello :name' => 'Hello :name', 'Only English' => 'Only English']));
    File::put($dir.'/lang/fr.json', (string) json_encode(['Save' => 'Enregistrer', 'Hello :name' => 'Bonjour :name']));
    File::put($dir.'/lang/vendor/package/en/messages.php', "<?php\n\nreturn ['title' => 'Package'];\n");

    File::put($dir.'/app/Http/Home.php', implode("\n", [
        '<?php',
        'namespace App\Http;',
        'final class Home {',
        '    public function a(): string { return __(\'messages.welcome\'); }',
        '    public function b(): string { return \__("messages.nested.title"); }',
        '    public function c(): string { return trans(\'messages.unknown\', [\'a\' => 1]); }',
        '    public function d(): string { return trans_choice(\'Only English\', 2); }',
        '    public function e(string $k): string { return __($k).__(\'messages.\'.$k).__("messages.$k"); }',
        '    public function f(): mixed { return $this->trans(\'ignored.method\') ?? Foo::__(\'ignored.static\') ?? trans(); }',
        '    public function trans(string $k): mixed { return null; }',
        '    // __(\'ignored.comment\')',
        '    public string $text = "__(\'ignored.string\')";',
        '    public function g(): string { return __(\'Save\').__(\'package::messages.title\').__(key: \'messages.only_en\'); }',
        '}',
        '',
    ]));

    File::put($dir.'/resources/views/home.blade.php', implode("\n", [
        '<h1>{{ __(\'messages.welcome\') }}</h1>',
        '<p>@lang(\'messages.nested.title\')</p>',
        '{{-- __(\'ignored.blade_comment\') --}}',
        '<p>{{ __(\'Hello :name\', [\'name\' => $user]) }} {{ __($dynamic) }}</p>',
        '<p>{{ $t->trans(\'ignored.blade_method\') }} @choice(\'messages.apples\', 3)</p>',
        '',
    ]));

    File::put($dir.'/legacy/old.php', "<?php\n\n__('messages.legacy');\n");
    File::put($dir.'/app/readme.txt', "__('messages.not_php')\n");

    return $dir;
}

beforeEach(function (): void {
    $this->langDir = langMissingFixture();
    $this->langOptions = [
        'scan_paths' => [$this->langDir],
        'exclude_paths' => [$this->langDir.'/legacy'],
        'lang_path' => $this->langDir.'/lang',
    ];
});

afterEach(function (): void {
    File::deleteDirectory($this->langDir);
});

it('is registered as a scanner with a typed schema', function (): void {
    $scanner = app(DevtoolboxManager::class)->registry()->get('lang-missing');

    expect($scanner->getName())->toBe('lang-missing')
        ->and($scanner->getDescription())->toContain('__()')
        ->and(array_keys($scanner->getOptionSchema()))->toBe(['scan_paths', 'exclude_paths', 'locales', 'lang_path']);
});

it('reports the used keys that have no translation, per locale', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing', $this->langOptions);

    $missing = array_map(fn (array $entry): array => [
        $entry['key'],
        $entry['missing_in'],
        array_map(fn (array $usage): string => basename($usage['file']).':'.$usage['line'], $entry['usages']),
    ], $result['data']['missing']);

    expect($result['type'])->toBe('lang-missing')
        ->and($result['metadata']['count'])->toBe(5)
        ->and($result['data']['locales'])->toBe(['en', 'fr'])
        ->and($missing)->toBe([
            ['Only English', ['fr'], ['Home.php:7']],
            ['messages.apples', ['en', 'fr'], ['home.blade.php:5']],
            ['messages.nested.title', ['fr'], ['Home.php:5', 'home.blade.php:2']],
            ['messages.only_en', ['fr'], ['Home.php:13']],
            ['messages.unknown', ['en', 'fr'], ['Home.php:6']],
        ]);
});

it('summarises the scan', function (): void {
    $summary = app(DevtoolboxManager::class)->scan('lang-missing', $this->langOptions)['data']['summary'];

    expect($summary['used_keys'])->toBe(8)
        ->and($summary['missing_keys'])->toBe(5)
        ->and($summary['missing_translations'])->toBe(7)
        ->and($summary['by_locale'])->toBe(['en' => 2, 'fr' => 5])
        ->and($summary['dynamic_calls'])->toBe(4)
        ->and($summary['namespaced_keys'])->toBe(1)
        ->and($summary['scanned_files'])->toBe(2)
        ->and($summary['lang_path'])->toEndWith('/lang');
});

it('ignores method calls, comments, strings, excluded paths and the lang directory', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing', $this->langOptions);

    $keys = array_column($result['data']['missing'], 'key');

    expect(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'ignored.')))->toBe([])
        ->and($keys)->not->toContain('messages.legacy')
        ->and($keys)->not->toContain('messages.not_php')
        ->and($keys)->not->toContain('package::messages.title');
});

it('restricts the check to the requested locales', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing', [...$this->langOptions, 'locales' => ['en']]);

    expect($result['data']['locales'])->toBe(['en'])
        ->and(array_column($result['data']['missing'], 'key'))->toBe(['messages.apples', 'messages.unknown'])
        ->and($result['data']['summary']['by_locale'])->toBe(['en' => 2]);
});

it('reports every key as missing for a requested locale without language files', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing', [...$this->langOptions, 'locales' => ['de', '../fr']]);

    expect($result['data']['locales'])->toBe(['de'])
        ->and($result['data']['summary']['missing_keys'])->toBe(8)
        ->and($result['data']['summary']['by_locale'])->toBe(['de' => 8]);
});

it('accepts a group name alone as a key when the group file exists', function (): void {
    File::put($this->langDir.'/app/groups.php', "<?php\n\nreturn [__('messages'), __('other')];\n");

    $result = app(DevtoolboxManager::class)->scan('lang-missing', [...$this->langOptions, 'scan_paths' => [$this->langDir.'/app/groups.php']]);

    expect(array_column($result['data']['missing'], 'key'))->toBe(['other'])
        ->and($result['data']['missing'][0]['missing_in'])->toBe(['en', 'fr']);
});

it('keeps the line of multi-line Blade calls and unescapes quoted keys', function (): void {
    File::put($this->langDir.'/resources/views/multi.blade.php', implode("\n", [
        '{{--',
        '    __(\'ignored.multi_line_comment\')',
        '--}}',
        '<p>{{ __(',
        '    \'messages.multi_line\'',
        ') }}</p>',
        '<p>{{ __(\'It\\\'s missing\') }}</p>',
        '',
    ]));

    $result = app(DevtoolboxManager::class)->scan('lang-missing', [...$this->langOptions, 'scan_paths' => [$this->langDir.'/resources/views/multi.blade.php']]);

    expect(array_map(fn (array $entry): array => [$entry['key'], $entry['usages'][0]['line']], $result['data']['missing']))
        ->toBe([
            ["It's missing", 7],
            ['messages.multi_line', 4],
        ]);
});

it('reports nothing when the lang directory has no locale', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing', [...$this->langOptions, 'lang_path' => $this->langDir.'/empty-lang']);

    expect($result['data']['locales'])->toBe([])
        ->and($result['data']['missing'])->toBe([])
        ->and($result['data']['summary']['used_keys'])->toBeGreaterThan(0);
});

it('scans the default application paths when no option is given', function (): void {
    $result = app(DevtoolboxManager::class)->scan('lang-missing');

    expect($result['data'])->toHaveKeys(['missing', 'locales', 'summary'])
        ->and($result['data']['summary'])->toHaveKeys(['lang_path', 'used_keys', 'missing_keys', 'missing_translations', 'by_locale', 'dynamic_calls', 'namespaced_keys', 'scanned_files']);
});

it('prints the missing keys from the artisan command', function (): void {
    $this->artisan('dev:lang:missing', ['--path' => [$this->langDir.'/app'], '--lang-path' => $this->langDir.'/lang'])
        ->expectsOutputToContain('messages.unknown')
        ->expectsOutputToContain('4 key(s) used in the code without a translation, 5 missing translation(s) across 2 locale(s).')
        ->expectsOutputToContain('3 call(s) build their key dynamically')
        ->assertExitCode(0);
});

it('outputs json from the artisan command', function (): void {
    $file = $this->langDir.'/result.json';

    $this->artisan('dev:lang:missing', [
        '--path' => [$this->langDir.'/resources'],
        '--lang-path' => $this->langDir.'/lang',
        '--locale' => ['fr'],
        '--format' => 'json',
        '--output' => $file,
    ])->assertExitCode(0);

    $json = json_decode((string) file_get_contents($file), true);

    expect($json['type'])->toBe('lang-missing')
        ->and($json['data']['locales'])->toBe(['fr'])
        ->and(array_column($json['data']['missing'], 'key'))->toBe(['messages.apples', 'messages.nested.title']);
});

it('fails with --fail-on-missing only when translations are missing', function (): void {
    $this->artisan('dev:lang:missing', ['--path' => [$this->langDir.'/app'], '--lang-path' => $this->langDir.'/lang', '--fail-on-missing' => true])
        ->assertExitCode(1);

    File::put($this->langDir.'/app/Http/Home.php', "<?php\n\nreturn __('messages.welcome');\n");

    $this->artisan('dev:lang:missing', ['--path' => [$this->langDir.'/app'], '--lang-path' => $this->langDir.'/lang', '--fail-on-missing' => true])
        ->expectsOutputToContain('No missing translation: 1 key(s) checked in 2 locale(s) (en, fr).')
        ->assertExitCode(0);
});

it('warns when no locale is found', function (): void {
    $this->artisan('dev:lang:missing', ['--path' => [$this->langDir.'/app'], '--lang-path' => $this->langDir.'/empty-lang', '--fail-on-missing' => true])
        ->expectsOutputToContain('No locale found in')
        ->assertExitCode(0);
});
