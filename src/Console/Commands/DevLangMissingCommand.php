<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevLangMissingCommand extends Command
{
    protected $signature = 'dev:lang:missing
                            {--path=* : Files or directories to scan for translation calls (defaults to app, resources and routes)}
                            {--exclude=* : Files or directories to skip}
                            {--locale=* : Locales to check (defaults to every locale found in the lang directory)}
                            {--lang-path= : Language directory (defaults to the application lang path)}
                            {--fail-on-missing : Exit with a failure code when translations are missing (useful in CI)}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Find translation keys used in the code that have no translation in one or more locales';

    public function handle(DevtoolboxManager $manager): int
    {
        $format = $this->option('format');
        $output = $this->option('output');

        if ($format !== 'json') {
            $this->info('🔍 Searching for translation keys without a translation...');
        }

        try {
            $result = $manager->scan('lang-missing', [
                'scan_paths' => $this->option('path'),
                'exclude_paths' => $this->option('exclude'),
                'locales' => $this->option('locale'),
                'lang_path' => $this->option('lang-path'),
            ]);
        } catch (Exception $e) {
            $this->error('Error searching for missing translations: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($output) {
            file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT));
            if ($format !== 'json') {
                $this->info("Results saved to: {$output}");
            }
        } elseif ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($result['data'] ?? []);
        }

        $found = count($result['data']['missing'] ?? []);

        return $this->option('fail-on-missing') && $found > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function displayResults(array $data): void
    {
        $missing = $data['missing'] ?? [];
        $locales = $data['locales'] ?? [];
        $summary = $data['summary'] ?? [];

        if ($locales === []) {
            $this->warn('⚠️  No locale found in '.($summary['lang_path'] ?? 'the lang directory').': nothing to compare.');

            return;
        }

        if ($missing === []) {
            $this->info(sprintf(
                '✅ No missing translation: %d key(s) checked in %d locale(s) (%s).',
                $summary['used_keys'] ?? 0,
                count($locales),
                implode(', ', $locales),
            ));
            $this->displayNotes($summary);

            return;
        }

        $this->table(
            ['Key', 'Missing in', 'Used in'],
            array_map(function (array $entry): array {
                $usages = $entry['usages'] ?? [];
                $first = $usages[0] ?? null;
                $usedIn = $first === null ? '-' : $first['file'].':'.$first['line'];

                if (count($usages) > 1) {
                    $usedIn .= ' (+'.(count($usages) - 1).' more)';
                }

                return [
                    mb_strimwidth((string) $entry['key'], 0, 60, '...'),
                    implode(', ', $entry['missing_in']),
                    $usedIn,
                ];
            }, $missing),
        );

        $this->newLine();
        $this->warn(sprintf(
            '⚠️  %d key(s) used in the code without a translation, %d missing translation(s) across %d locale(s).',
            $summary['missing_keys'] ?? count($missing),
            $summary['missing_translations'] ?? 0,
            count($locales),
        ));

        foreach ($summary['by_locale'] ?? [] as $locale => $count) {
            $this->line(sprintf('   %s: %d missing', $locale, $count));
        }

        $this->displayNotes($summary);
    }

    private function displayNotes(array $summary): void
    {
        $dynamic = $summary['dynamic_calls'] ?? 0;

        if ($dynamic > 0) {
            $this->line(sprintf('💡 %d call(s) build their key dynamically and could not be checked.', $dynamic));
        }
    }
}
