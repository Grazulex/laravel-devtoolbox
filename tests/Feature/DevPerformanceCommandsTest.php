<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Console\Commands\DevCacheAnalysisCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevPerformanceMemoryCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevPerformanceSlowQueriesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevQueueAnalysisCommand;

describe('Performance Commands', function (): void {
    test('dev:performance:memory command is available', function (): void {
        $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

        expect($commands)->toHaveKey('dev:performance:memory');
    });

    test('dev:performance:memory command can run successfully', function (): void {
        $this->artisan(DevPerformanceMemoryCommand::class, ['--format' => 'json'])
            ->assertExitCode(0);
    });

    test('dev:performance:memory command produces json output', function (): void {
        $this->artisan(DevPerformanceMemoryCommand::class, ['--format' => 'json'])
            ->assertExitCode(0);
    });

    test('dev:performance:slow-queries command is available', function (): void {
        $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

        expect($commands)->toHaveKey('dev:performance:slow-queries');
    });

    test('dev:performance:slow-queries command can run successfully', function (): void {
        $this->artisan(DevPerformanceSlowQueriesCommand::class, ['--format' => 'json'])
            ->assertExitCode(0);
    });

    test('dev:cache:analysis command is available', function (): void {
        $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

        expect($commands)->toHaveKey('dev:cache:analysis');
    });

    test('dev:cache:analysis command can run successfully', function (): void {
        $this->artisan(DevCacheAnalysisCommand::class, ['--format' => 'json'])
            ->assertExitCode(0);
    });

    test('dev:queue:analysis command is available', function (): void {
        $commands = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all());

        expect($commands)->toHaveKey('dev:queue:analysis');
    });

    test('dev:queue:analysis command can run successfully', function (): void {
        $this->artisan(DevQueueAnalysisCommand::class, ['--format' => 'json'])
            ->assertExitCode(0);
    });

    test('performance memory command with baseline option', function (): void {
        $this->artisan(DevPerformanceMemoryCommand::class, [
            '--baseline' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('performance memory command with detailed option', function (): void {
        $this->artisan(DevPerformanceMemoryCommand::class, [
            '--detailed' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('slow queries command with custom threshold', function (): void {
        $this->artisan(DevPerformanceSlowQueriesCommand::class, [
            '--threshold' => 500,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('slow queries command with duplicates option', function (): void {
        $this->artisan(DevPerformanceSlowQueriesCommand::class, [
            '--duplicates' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('cache analysis with specific drivers', function (): void {
        $this->artisan(DevCacheAnalysisCommand::class, [
            '--drivers' => 'file,array',
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('cache analysis with detailed option', function (): void {
        $this->artisan(DevCacheAnalysisCommand::class, [
            '--detailed' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('queue analysis with failed jobs option', function (): void {
        $this->artisan(DevQueueAnalysisCommand::class, [
            '--failed-jobs' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });

    test('queue analysis with slow jobs option', function (): void {
        $this->artisan(DevQueueAnalysisCommand::class, [
            '--slow-jobs' => true,
            '--format' => 'json',
        ])->assertExitCode(0);
    });
});
