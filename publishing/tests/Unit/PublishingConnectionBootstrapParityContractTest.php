<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoDatabaseRequestBootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PublishingConnectionBootstrapParityContractTest extends TestCase
{
    public function test_http_and_console_share_canonical_bootstrap_service(): void
    {
        $http = (string) file_get_contents(
            (string) (new ReflectionClass(SeoDatabaseRequestBootstrap::class))->getFileName(),
        );
        $console = (string) file_get_contents(
            (string) (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );

        self::assertStringContainsString('bootstrapByHash', $http);
        self::assertStringContainsString('SeoDatabaseConnectionService', $http);
        self::assertStringContainsString('bootstrapAndVerifyFromConnection', $console);
        self::assertStringContainsString('SeoDatabaseConnectionService', $console);
        self::assertStringNotContainsString('Config::set(\'database.connections', $console);
        self::assertStringNotContainsString('getRawOriginal(\'password\')', $console);
        self::assertStringNotContainsString('Crypt::decrypt', $console);
    }

    public function test_manual_password_uses_cast_accessor_not_raw_attributes(): void
    {
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(SeoDatabaseConnectionService::class))->getFileName(),
        );

        self::assertStringContainsString('function plainPasswordFromModel', $service);
        self::assertStringContainsString('plainPasswordFromModel($connection)', $service);
        self::assertStringContainsString('getAttribute(\'password\')', $service);
        self::assertDoesNotMatchRegularExpression(
            '/getRawOriginal\s*\(\s*[\'"]password[\'"]\s*\)/',
            $service,
        );
        self::assertStringContainsString('function bootstrapAndVerifyFromConnection', $service);
        self::assertStringContainsString('select database()', $service);
        self::assertStringContainsString('DB::purge', $service);
    }

    public function test_schema_probe_runs_after_verify_and_not_labeled_on_auth_fail(): void
    {
        $runner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );

        self::assertStringContainsString('rememberWorkerRun()', $runner);
        self::assertLessThan(
            strpos($runner, 'rememberWorkerRun()') ?: PHP_INT_MAX,
            strpos($runner, 'hasColumn') ?: 0,
        );
        self::assertStringContainsString('publishing.connection_bootstrap_failed', $runner);
        self::assertStringContainsString('looksLikeConnectionFailure', $runner);
        self::assertStringContainsString('content_project_publishing_queue_schema_unavailable', $runner);
    }

    public function test_console_isolates_connection_contexts(): void
    {
        $console = (string) file_get_contents(
            (string) (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );

        self::assertStringContainsString('forceReconnect: true', $console);
        self::assertStringContainsString('forgetBootstrappedHash', $console);
        self::assertStringContainsString('SeoConnectionContext::reset()', $console);
        self::assertStringContainsString('publishing.connection_bootstrap_failed', $console);
        self::assertStringNotContainsString(
            'Scheduled article publish: connection bootstrap failed.',
            $console,
        );
    }

    public function test_health_requires_successful_due_scan_not_heartbeat_alone(): void
    {
        $health = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectQueueHealthService::class))->getFileName(),
        );

        self::assertStringContainsString('CACHE_LAST_BOOTSTRAP_FAILURE', $health);
        self::assertStringContainsString('rememberBootstrapFailure', $health);
        self::assertStringContainsString("runnerLabel('connection_failed')", $health);
        self::assertStringContainsString('$schedulerHeartbeat && ! $recentBootstrapFailure', $health);
        self::assertStringContainsString('scopedKey', $health);
        self::assertStringContainsString('health_connection_id', $health);
    }

    public function test_bootstrap_and_verify_method_signature(): void
    {
        $method = new ReflectionMethod(SeoDatabaseConnectionService::class, 'bootstrapAndVerifyFromConnection');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());
    }
}
