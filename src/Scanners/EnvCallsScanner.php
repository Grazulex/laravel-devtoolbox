<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Finds env() calls outside the config directory.
 *
 * Once the configuration is cached (php artisan config:cache), the .env file is
 * no longer loaded and env() returns null everywhere except in the cached config
 * values, so env() calls must only appear in config files.
 */
final class EnvCallsScanner extends AbstractScanner
{
    /**
     * Directories scanned when no scan_paths option is given, relative to the project root.
     *
     * @var list<string>
     */
    private const DEFAULT_PATHS = ['app', 'bootstrap', 'database', 'resources', 'routes'];

    /**
     * Directories always skipped, relative to the project root (config/ is resolved separately).
     *
     * @var list<string>
     */
    private const ALWAYS_SKIPPED = ['bootstrap/cache', 'vendor', 'node_modules', 'storage'];

    /**
     * Tokens that, right before `env`, mean it is not a call to the global env() helper.
     *
     * @var list<int>
     */
    private const NON_CALL_PREFIXES = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    public function getName(): string
    {
        return 'env-calls';
    }

    public function getDescription(): string
    {
        return 'Find env() calls outside the config directory, which return null once the configuration is cached';
    }

    public function getOptionSchema(): array
    {
        return [
            'scan_paths' => ['type' => 'array', 'description' => 'Files or directories to scan, absolute or relative to the project root (defaults to app, bootstrap, database, resources and routes); config/ is always skipped'],
            'exclude_paths' => ['type' => 'array', 'description' => 'Files or directories to skip, absolute or relative to the project root', 'default' => []],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $scanPaths = $options['scan_paths'] ?? [];
        if (! is_array($scanPaths) || $scanPaths === []) {
            $scanPaths = self::DEFAULT_PATHS;
        }

        $skipped = array_merge(
            [$this->normalize(config_path())],
            array_map(fn (string $path): string => $this->resolve($path), self::ALWAYS_SKIPPED),
            array_map(fn (mixed $path): string => $this->resolve((string) $path), (array) ($options['exclude_paths'] ?? [])),
        );

        $calls = [];
        $scannedFiles = 0;

        foreach ($this->collectFiles($scanPaths) as $file) {
            if ($this->isSkipped($file, $skipped)) {
                continue;
            }

            $scannedFiles++;

            foreach ($this->findCalls($file) as $call) {
                $calls[] = $call;
            }
        }

        usort($calls, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        $keys = array_values(array_unique(array_filter(array_column($calls, 'key'), fn (mixed $key): bool => is_string($key))));
        sort($keys);

        $result = $this->addMetadata([
            'calls' => $calls,
            'summary' => [
                'total_calls' => count($calls),
                'files_with_calls' => count(array_unique(array_column($calls, 'file'))),
                'scanned_files' => $scannedFiles,
                'keys' => $keys,
            ],
        ], $options);

        if (isset($result['metadata'])) {
            $result['metadata']['count'] = count($calls);
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $scanPaths
     * @return list<string>
     */
    private function collectFiles(array $scanPaths): array
    {
        $files = [];

        foreach ($scanPaths as $path) {
            $resolved = $this->resolve((string) $path);

            if (is_file($resolved)) {
                if (str_ends_with($resolved, '.php')) {
                    $files[$resolved] = true;
                }

                continue;
            }

            if (! is_dir($resolved)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (File::allFiles($resolved) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[$this->normalize($file->getPathname())] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @param  list<string>  $skipped
     */
    private function isSkipped(string $file, array $skipped): bool
    {
        foreach ($skipped as $path) {
            if ($file === $path || str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{file: string, line: int, key: string|null, code: string}>
     */
    private function findCalls(string $file): array
    {
        try {
            $content = File::get($file);
        } catch (Throwable) {
            return [];
        }

        if (mb_stripos($content, 'env') === false) {
            return [];
        }

        $lines = explode("\n", $content);
        $relative = $this->relativePath($file);
        $calls = [];

        $found = str_ends_with($file, '.blade.php')
            ? $this->findInBlade($content)
            : $this->findInPhp($content);

        foreach ($found as [$line, $key]) {
            $calls[] = [
                'file' => $relative,
                'line' => $line,
                'key' => $key,
                'code' => mb_trim($lines[$line - 1] ?? ''),
            ];
        }

        return $calls;
    }

    /**
     * @return list<array{0: int, 1: string|null}>
     */
    private function findInPhp(string $content): array
    {
        try {
            $tokens = token_get_all($content);
        } catch (Throwable) {
            return [];
        }

        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! $this->isEnvName($token)) {
                continue;
            }

            $previous = $this->significant($tokens, $i, -1);
            if ($previous !== null && is_array($tokens[$previous]) && in_array($tokens[$previous][0], self::NON_CALL_PREFIXES, true)) {
                continue;
            }

            $open = $this->significant($tokens, $i, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $key = null;
            $argument = $this->significant($tokens, $open, 1);
            if ($argument !== null && is_array($tokens[$argument]) && $tokens[$argument][0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = mb_substr($tokens[$argument][1], 1, -1);
            }

            $found[] = [$token[2], $key];
        }

        return $found;
    }

    /**
     * Blade templates are not valid PHP until compiled, so they are matched line by line.
     *
     * @return list<array{0: int, 1: string|null}>
     */
    private function findInBlade(string $content): array
    {
        $found = [];

        foreach (explode("\n", $content) as $index => $line) {
            if (preg_match_all('/(?<![\w$>:\\\\])\\\\?env\s*\(\s*(?:([\'"])([^\'"]*)\1)?/i', $line, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $found[] = [$index + 1, isset($match[2]) && $match[2] !== '' ? $match[2] : null];
            }
        }

        return $found;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}  $token
     */
    private function isEnvName(array $token): bool
    {
        $name = mb_strtolower($token[1]);

        return ($token[0] === T_STRING && $name === 'env')
            || ($token[0] === T_NAME_FULLY_QUALIFIED && $name === '\\env');
    }

    /**
     * Index of the nearest token in the given direction that is not whitespace or a comment.
     *
     * @param  array<int, mixed>  $tokens
     */
    private function significant(array $tokens, int $from, int $step): ?int
    {
        for ($i = $from + $step; $i >= 0 && $i < count($tokens); $i += $step) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function resolve(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $this->normalize($isAbsolute ? $path : base_path($path));
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);

        return mb_rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    private function relativePath(string $file): string
    {
        $base = $this->normalize(base_path());

        return str_starts_with($file, $base.'/') ? mb_substr($file, mb_strlen($base) + 1) : $file;
    }
}
