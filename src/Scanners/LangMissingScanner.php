<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Finds translation keys used in the code that have no translation in one or more locales.
 *
 * The keys are collected from __(), trans() and trans_choice() calls (and the
 *
 * @lang / @choice Blade directives), then looked up in the language files of
 * every locale: lang/{locale}.json and lang/{locale}/{group}.php.
 */
final class LangMissingScanner extends AbstractScanner
{
    /**
     * Directories scanned when no scan_paths option is given, relative to the project root.
     *
     * @var list<string>
     */
    private const DEFAULT_PATHS = ['app', 'resources', 'routes'];

    /**
     * Directories always skipped, relative to the project root (the lang directory is resolved separately).
     *
     * @var list<string>
     */
    private const ALWAYS_SKIPPED = ['bootstrap/cache', 'vendor', 'node_modules', 'storage'];

    /**
     * Global translation helpers whose first argument is a translation key.
     *
     * @var list<string>
     */
    private const HELPERS = ['__', 'trans', 'trans_choice'];

    /**
     * Tokens that, right before the helper name, mean it is not a call to the global helper.
     *
     * @var list<int>
     */
    private const NON_CALL_PREFIXES = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    /**
     * Matches a translation helper or Blade directive, its optional literal first argument
     * (single quoted, or double quoted without interpolation) and the character following it.
     */
    private const BLADE_PATTERN = '/(?:(?<![\w$>:\\\\])\\\\?(?:__|trans_choice|trans)|@(?:lang|choice))\s*\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")?\s*([,)])?/s';

    /**
     * PHP language files already loaded during the current scan, by locale then group.
     *
     * @var array<string, array<string, array<mixed>|null>>
     */
    private array $groups = [];

    public function getName(): string
    {
        return 'lang-missing';
    }

    public function getDescription(): string
    {
        return 'Find translation keys used in __(), trans() and trans_choice() calls that have no translation in one or more locales';
    }

    public function getOptionSchema(): array
    {
        return [
            'scan_paths' => ['type' => 'array', 'description' => 'Files or directories to scan for translation calls, absolute or relative to the project root (defaults to app, resources and routes); the lang directory is always skipped'],
            'exclude_paths' => ['type' => 'array', 'description' => 'Files or directories to skip, absolute or relative to the project root', 'default' => []],
            'locales' => ['type' => 'array', 'description' => 'Locales to check (defaults to every locale found in the lang directory)'],
            'lang_path' => ['type' => 'string', 'description' => 'Language directory, absolute or relative to the project root (defaults to the application lang path)'],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);
        $this->groups = [];

        $langPath = is_string($options['lang_path'] ?? null) && $options['lang_path'] !== ''
            ? $this->resolve($options['lang_path'])
            : $this->normalize(lang_path());

        $locales = $this->localeList($options['locales'] ?? []);
        if ($locales === []) {
            $locales = $this->discoverLocales($langPath);
        }

        $scanPaths = $options['scan_paths'] ?? [];
        if (! is_array($scanPaths) || $scanPaths === []) {
            $scanPaths = self::DEFAULT_PATHS;
        }

        $skipped = array_merge(
            [$langPath],
            array_map(fn (string $path): string => $this->resolve($path), self::ALWAYS_SKIPPED),
            array_map(fn (mixed $path): string => $this->resolve((string) $path), (array) ($options['exclude_paths'] ?? [])),
        );

        /** @var array<string, list<array{file: string, line: int}>> $usages */
        $usages = [];
        $dynamicCalls = 0;
        $namespacedKeys = 0;
        $scannedFiles = 0;

        foreach ($this->collectFiles($scanPaths) as $file) {
            if ($this->isSkipped($file, $skipped)) {
                continue;
            }

            $scannedFiles++;
            $found = $this->findKeys($file);
            $dynamicCalls += $found['dynamic'];
            $relative = $this->relativePath($file);

            foreach ($found['keys'] as [$line, $key]) {
                if ($key === '') {
                    continue;
                }

                if (str_contains($key, '::')) {
                    $namespacedKeys++;

                    continue;
                }

                $usages[$key][] = ['file' => $relative, 'line' => $line];
            }
        }

        ksort($usages, SORT_STRING);

        $json = [];
        foreach ($locales as $locale) {
            $json[$locale] = $this->jsonLines($langPath, $locale);
        }

        $missing = [];
        $byLocale = array_fill_keys($locales, 0);

        foreach ($usages as $key => $keyUsages) {
            $key = (string) $key;
            $missingIn = [];

            foreach ($locales as $locale) {
                if (! $this->isTranslated($key, $locale, $langPath, $json[$locale])) {
                    $missingIn[] = $locale;
                    $byLocale[$locale]++;
                }
            }

            if ($missingIn === []) {
                continue;
            }

            usort($keyUsages, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

            $missing[] = [
                'key' => $key,
                'missing_in' => $missingIn,
                'usages' => $keyUsages,
            ];
        }

        $this->groups = [];

        $result = $this->addMetadata([
            'missing' => $missing,
            'locales' => $locales,
            'summary' => [
                'lang_path' => $this->relativePath($langPath),
                'used_keys' => count($usages),
                'missing_keys' => count($missing),
                'missing_translations' => array_sum($byLocale),
                'by_locale' => $byLocale,
                'dynamic_calls' => $dynamicCalls,
                'namespaced_keys' => $namespacedKeys,
                'scanned_files' => $scannedFiles,
            ],
        ], $options);

        if (isset($result['metadata'])) {
            $result['metadata']['count'] = count($missing);
        }

        return $result;
    }

    /**
     * Locale names are used to build file paths, so anything but a plain locale code is dropped.
     *
     * @return list<string>
     */
    private function localeList(mixed $locales): array
    {
        $list = [];

        foreach ((array) $locales as $locale) {
            if (is_string($locale) && preg_match('/^[A-Za-z0-9_\-]+$/', $locale) === 1) {
                $list[$locale] = true;
            }
        }

        return array_map(fn (int|string $locale): string => (string) $locale, array_keys($list));
    }

    /**
     * A locale is a sub-directory of the lang directory (PHP files) or a {locale}.json file.
     *
     * @return list<string>
     */
    private function discoverLocales(string $langPath): array
    {
        if (! is_dir($langPath)) {
            return [];
        }

        $candidates = [];

        foreach (File::directories($langPath) as $directory) {
            $name = basename((string) $directory);

            if ($name !== 'vendor') {
                $candidates[] = $name;
            }
        }

        /** @var SplFileInfo $file */
        foreach (File::files($langPath) as $file) {
            if ($file->getExtension() === 'json') {
                $candidates[] = $file->getBasename('.json');
            }
        }

        $locales = $this->localeList($candidates);
        sort($locales, SORT_STRING);

        return $locales;
    }

    /**
     * @return array<mixed>
     */
    private function jsonLines(string $langPath, string $locale): array
    {
        $file = $langPath.'/'.$locale.'.json';

        if (! is_file($file)) {
            return [];
        }

        try {
            $decoded = json_decode(File::get($file), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lines of lang/{locale}/{group}.php, or null when the file does not exist.
     *
     * @return array<mixed>|null
     */
    private function groupLines(string $langPath, string $locale, string $group): ?array
    {
        if (array_key_exists($group, $this->groups[$locale] ?? [])) {
            return $this->groups[$locale][$group];
        }

        $file = $langPath.'/'.$locale.'/'.$group.'.php';
        $lines = null;

        if (is_file($file)) {
            try {
                $loaded = File::getRequire($file);
                $lines = is_array($loaded) ? $loaded : [];
            } catch (Throwable) {
                $lines = [];
            }
        }

        return $this->groups[$locale][$group] = $lines;
    }

    /**
     * Mirrors the translator lookup: the whole key in the JSON file first,
     * then {group}.{item} in the PHP file of the group.
     *
     * @param  array<mixed>  $json
     */
    private function isTranslated(string $key, string $locale, string $langPath, array $json): bool
    {
        if (array_key_exists($key, $json)) {
            return true;
        }

        $segments = explode('.', $key, 2);
        $group = $segments[0];

        if (preg_match('/^[\w\-]+(?:\/[\w\-]+)*$/', $group) !== 1) {
            return false;
        }

        $lines = $this->groupLines($langPath, $locale, $group);

        if ($lines === null) {
            return false;
        }

        return ! isset($segments[1]) || Arr::has($lines, $segments[1]);
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

        return array_map(fn (int|string $file): string => (string) $file, array_keys($files));
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
     * Literal keys found in the file, and the number of calls whose key is not a literal string.
     *
     * @return array{keys: list<array{0: int, 1: string}>, dynamic: int}
     */
    private function findKeys(string $file): array
    {
        $nothing = ['keys' => [], 'dynamic' => 0];

        try {
            $content = File::get($file);
        } catch (Throwable) {
            return $nothing;
        }

        if (! str_contains($content, '__') && ! str_contains($content, 'trans') && ! str_contains($content, '@lang') && ! str_contains($content, '@choice')) {
            return $nothing;
        }

        return str_ends_with($file, '.blade.php')
            ? $this->findInBlade($content)
            : $this->findInPhp($content);
    }

    /**
     * @return array{keys: list<array{0: int, 1: string}>, dynamic: int}
     */
    private function findInPhp(string $content): array
    {
        try {
            $tokens = token_get_all($content);
        } catch (Throwable) {
            return ['keys' => [], 'dynamic' => 0];
        }

        $keys = [];
        $dynamic = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! $this->isHelperName($token)) {
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

            $argument = $this->significant($tokens, $open, 1);
            if ($argument === null || $tokens[$argument] === ')') {
                continue; // trans() without argument returns the translator
            }

            // Named argument: __(key: 'messages.welcome')
            $after = $this->significant($tokens, $argument, 1);
            if (is_array($tokens[$argument]) && $tokens[$argument][0] === T_STRING && $after !== null && $tokens[$after] === ':') {
                if (mb_strtolower($tokens[$argument][1]) !== 'key') {
                    $dynamic++;

                    continue;
                }

                $argument = $this->significant($tokens, $after, 1);
                $after = $argument === null ? null : $this->significant($tokens, $argument, 1);
            }

            $isLiteral = $argument !== null
                && is_array($tokens[$argument])
                && $tokens[$argument][0] === T_CONSTANT_ENCAPSED_STRING
                && $after !== null
                && in_array($tokens[$after], [',', ')'], true);

            if (! $isLiteral) {
                $dynamic++;

                continue;
            }

            $literal = $tokens[$argument][1];
            $keys[] = [$token[2], $this->unescape(mb_substr($literal, 1, -1), $literal[0] === '"')];
        }

        return ['keys' => $keys, 'dynamic' => $dynamic];
    }

    /**
     * Blade templates are not valid PHP until compiled, so they are matched with a pattern.
     *
     * @return array{keys: list<array{0: int, 1: string}>, dynamic: int}
     */
    private function findInBlade(string $content): array
    {
        $nothing = ['keys' => [], 'dynamic' => 0];

        // Blade comments are blanked, keeping their line breaks so that line numbers stay right.
        $content = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn (array $match): string => str_repeat("\n", mb_substr_count($match[0], "\n")),
            $content,
        ) ?? $content;

        if (preg_match_all(self::BLADE_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return $nothing;
        }

        if ($matches === []) {
            return $nothing;
        }

        $lineBreaks = [];
        if (preg_match_all('/\n/', $content, $breaks, PREG_OFFSET_CAPTURE) !== false) {
            $lineBreaks = array_column($breaks[0], 1);
        }

        $keys = [];
        $dynamic = 0;
        $line = 1;
        $breakIndex = 0;
        $breakCount = count($lineBreaks);

        foreach ($matches as $match) {
            $single = $match[1][0] ?? null;
            $double = $match[2][0] ?? null;
            $next = $match[3][0] ?? null;

            if ($single === null && $double === null) {
                if ($next !== ')') {
                    $dynamic++;
                }

                continue;
            }

            if ($next === null) {
                $dynamic++; // the literal is only a part of an expression, e.g. 'messages.'.$name

                continue;
            }

            // Matches come in order, so the line counter only moves forward.
            while ($breakIndex < $breakCount && $lineBreaks[$breakIndex] < $match[0][1]) {
                $line++;
                $breakIndex++;
            }

            $keys[] = [$line, $single !== null ? $this->unescape($single, false) : $this->unescape((string) $double, true)];
        }

        return ['keys' => $keys, 'dynamic' => $dynamic];
    }

    /**
     * Value of a PHP string literal, given its content without the surrounding quotes.
     */
    private function unescape(string $body, bool $doubleQuoted): string
    {
        return $doubleQuoted
            ? stripcslashes($body)
            : strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
    }

    /**
     * @param  array{0: int, 1: string, 2: int}  $token
     */
    private function isHelperName(array $token): bool
    {
        $name = mb_strtolower($token[1]);

        return match ($token[0]) {
            T_STRING => in_array($name, self::HELPERS, true),
            T_NAME_FULLY_QUALIFIED => in_array(mb_ltrim($name, '\\'), self::HELPERS, true),
            default => false,
        };
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
