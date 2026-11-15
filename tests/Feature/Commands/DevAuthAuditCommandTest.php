<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Grazulex\LaravelDevtoolbox\Scanners\SecurityScanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AuditPostModel extends Model {}

final class AuditNoPolicyModel extends Model {}

final class AuditBrokenModel extends Model {}

final class AuditPostPolicy
{
    public function view(): bool
    {
        return true;
    }
}

final class DevAuthAuditCommandTest extends TestCase
{
    public function test_command_runs_with_table_and_json_formats(): void
    {
        $this->artisan('dev:auth:audit')->assertExitCode(0);
        $this->artisan('dev:auth:audit --format=json')->assertExitCode(0);
    }

    public function test_scanner_flags_missing_policies_and_abilities(): void
    {
        Gate::policy(AuditPostModel::class, AuditPostPolicy::class);
        Gate::policy(AuditBrokenModel::class, 'Nonexistent\\Policy');

        Route::get('/posts/{post}', fn (AuditPostModel $post) => 'ok')->middleware('can:view,post');
        Route::delete('/posts/{post}/remove', fn (AuditPostModel $post) => 'ok')->middleware('can:delete,post');
        Route::get('/orphans/{orphan}', fn (AuditNoPolicyModel $orphan) => 'ok')->middleware('can:view,orphan');
        Route::get('/broken/{broken}', fn (AuditBrokenModel $broken) => 'ok')->middleware('can:view,broken');
        Route::get('/plain/{orphan}', fn (AuditNoPolicyModel $orphan) => 'ok');
        Route::get('/free', fn () => 'ok');

        $result = (new SecurityScanner($this->app))->scan([
            'check_unprotected_routes' => false,
            'check_csrf_protection' => false,
            'check_authorization' => true,
        ]);

        $found = [];
        foreach ($result['data']['authorization_issues'] as $issue) {
            $found[$issue['uri']] = $issue['type'];
        }

        $this->assertArrayNotHasKey('posts/{post}', $found);
        $this->assertSame('missing_ability', $found['posts/{post}/remove']);
        $this->assertSame('missing_policy', $found['orphans/{orphan}']);
        $this->assertSame('nonexistent_policy', $found['broken/{broken}']);
        $this->assertSame('model_without_policy', $found['plain/{orphan}']);
        $this->assertArrayNotHasKey('free', $found);
    }

    public function test_authorization_check_is_disabled_by_default(): void
    {
        $result = (new SecurityScanner($this->app))->scan();

        $this->assertArrayNotHasKey('authorization_issues', $result['data']);
    }
}
