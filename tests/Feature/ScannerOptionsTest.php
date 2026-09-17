<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\DatabaseColumnUsageScanner;
use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

describe('RouteScanner filter_methods', function (): void {
    beforeEach(function (): void {
        Route::get('/opt/get-only', fn (): string => 'get')->name('opt.get');
        Route::post('/opt/post-only', fn (): string => 'post')->name('opt.post');
        Route::match(['put', 'patch'], '/opt/put-patch', fn (): string => 'put')->name('opt.put');
    });

    it('keeps only routes whose methods intersect the given list, case-insensitively', function (): void {
        $data = (new RouteScanner($this->app))->scan(['filter_methods' => ['post', 'PATCH'], 'include_metadata' => false]);

        $names = array_column($data['routes'], 'name');

        expect($names)->toContain('opt.post', 'opt.put')
            ->and($names)->not->toContain('opt.get')
            ->and($data['count'])->toBe(count($data['routes']));
    });

    it('returns every route when filter_methods is empty', function (): void {
        $data = (new RouteScanner($this->app))->scan(['filter_methods' => [], 'include_metadata' => false]);

        expect(array_column($data['routes'], 'name'))->toContain('opt.get', 'opt.post', 'opt.put');
    });

    it('applies the filter before unused detection', function (): void {
        $data = (new RouteScanner($this->app))->scan(['filter_methods' => ['GET'], 'detect_unused' => true, 'include_metadata' => false]);

        expect(array_column($data['unused_routes'], 'name'))->not->toContain('opt.post');
    });
});

describe('DatabaseColumnUsageScanner unused_only', function (): void {
    beforeEach(function (): void {
        Schema::create('opt_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('legacy_slug');
        });
        Schema::create('opt_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        $this->scanPath = sys_get_temp_dir().'/devtoolbox-column-usage-'.uniqid();
        File::ensureDirectoryExists($this->scanPath);
        File::put($this->scanPath.'/ArticleController.php', "<?php\n\$q->where('title', 'x')->orderBy('id')->get(['label']);\n");
    });

    afterEach(function (): void {
        File::deleteDirectory($this->scanPath);
    });

    it('returns only unused columns and drops tables without any', function (): void {
        $data = (new DatabaseColumnUsageScanner($this->app))->scan([
            'tables' => ['opt_articles', 'opt_tags'],
            'scan_paths' => [$this->scanPath],
            'unused_only' => true,
            'include_metadata' => false,
        ]);

        expect(array_keys($data['column_usage']))->toBe(['opt_articles'])
            ->and(array_keys($data['column_usage']['opt_articles']))->toBe(['legacy_slug'])
            ->and($data['column_usage']['opt_articles']['legacy_slug']['used'])->toBeFalse()
            ->and($data['summary']['total_columns'])->toBe(5)
            ->and($data['summary']['used_columns'])->toBe(4)
            ->and($data['summary']['unused_columns'])->toBe(1)
            ->and($data['summary']['tables_summary']['opt_articles'])->toMatchArray(['total' => 3, 'used' => 2, 'unused' => 1])
            ->and($data['summary']['tables_summary']['opt_tags'])->toMatchArray(['total' => 2, 'used' => 2, 'unused' => 0]);
    });

    it('returns every column by default', function (): void {
        $data = (new DatabaseColumnUsageScanner($this->app))->scan([
            'tables' => ['opt_articles', 'opt_tags'],
            'scan_paths' => [$this->scanPath],
            'include_metadata' => false,
        ]);

        expect(array_keys($data['column_usage']['opt_articles']))->toBe(['id', 'title', 'legacy_slug'])
            ->and(array_keys($data['column_usage']))->toBe(['opt_articles', 'opt_tags']);
    });
});
