<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Console\PublishScheduledArticlesCommand;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\PublishingConnectionCandidateResolver;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use App\Models\SeoDatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublishingConnectionIsolationContractTest extends TestCase
{
    public function test_stale_demo_orphan_manual_is_skipped(): void
    {
        $resolver = new PublishingConnectionCandidateResolver;
        $stale = new SeoDatabaseConnection([
            'name' => 'Demo keywords',
            'hash_id' => 'GrTnWJ8zvIoT6L2P29XN2wcrwEMzgvAv',
            'type' => 'manual',
            'database' => 'lzxzdusj_demo_keywords',
            'username' => 'lzxzdusj_demo_keywords',
            'is_active' => true,
        ]);
        $stale->users_count = 0;

        self::assertSame('orphan_demo_no_users', $resolver->isEligible($stale));
        self::assertTrue($resolver->looksLikeDemoOrLegacyOrphanDatabase('lzxzdusj_demo_keywords'));

        $staleWithUsers = new SeoDatabaseConnection([
            'type' => 'manual',
            'database' => 'lzxzdusj_demo_keywords',
            'is_active' => true,
        ]);
        $staleWithUsers->users_count = 3;
        self::assertSame('demo_database', $resolver->isEligible($staleWithUsers));
    }

    public function test_active_omi_seo_ai_auto_is_eligible(): void
    {
        $resolver = new PublishingConnectionCandidateResolver;
        $ok = new SeoDatabaseConnection([
            'name' => 'Legacy Shared',
            'hash_id' => 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G',
            'type' => 'auto',
            'database' => 'omi_seo_ai',
            'is_active' => true,
        ]);
        $ok->users_count = 1;

        self::assertNull($resolver->isEligible($ok));
    }

    public function test_inactive_connection_skipped(): void
    {
        $resolver = new PublishingConnectionCandidateResolver;
        $row = new SeoDatabaseConnection([
            'type' => 'manual',
            'database' => 'omi_seo_ai',
            'is_active' => false,
        ]);
        $row->users_count = 2;

        self::assertSame('inactive', $resolver->isEligible($row));
    }

    public function test_runner_uses_canonical_shared_bootstrap_only(): void
    {
        $runner = (string) file_get_contents(
            (string) (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );

        self::assertStringContainsString('resolveDefaultSharedConnectionRecord', $runner);
        self::assertStringContainsString('bootstrapLegacySharedConnection', $runner);
        self::assertStringContainsString('canonical_shared', $runner);
        self::assertStringNotContainsString('SeoDatabaseConnection::query()', $runner);
        self::assertStringNotContainsString("hasTable('seo_database_connections')", $runner);
        self::assertStringContainsString('eligibleForPublishingScan', (string) file_get_contents(
            (string) (new ReflectionClass(PublishingConnectionCandidateResolver::class))->getFileName(),
        ));
    }

    public function test_health_is_connection_scoped_and_hub_passes_context(): void
    {
        $health = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectQueueHealthService::class))->getFileName(),
        );
        $hub = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingQueueHub::class))->getFileName(),
        );
        $queueRunner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );

        self::assertStringContainsString('scopedKey', $health);
        self::assertStringContainsString('health_connection_id', $health);
        self::assertStringContainsString(
            'Never write unscoped global bootstrap failure from a known connection',
            $health,
        );
        self::assertStringNotContainsString('SeoDatabaseConnection::query()', $health);
        self::assertStringContainsString('SeoConnectionContext::current()', $hub);
        self::assertStringContainsString('snapshot($siteIds, $connectionId, $projectId)', $hub);
        self::assertStringContainsString('rememberBootstrapFailure(', $queueRunner);
        self::assertStringContainsString('rememberWorkerRun($scopedConnectionId)', $queueRunner);
    }

    public function test_command_reports_connection_isolation_stats(): void
    {
        $command = (string) file_get_contents(
            (string) (new ReflectionClass(PublishScheduledArticlesCommand::class))->getFileName(),
        );

        self::assertStringContainsString('connections_attempted', $command);
        self::assertStringContainsString('connections_skipped', $command);
        self::assertStringContainsString('bootstrap_failed', $command);
    }

    public function test_health_scoped_key_helper(): void
    {
        $health = new ContentProjectQueueHealthService;
        self::assertSame(
            ContentProjectQueueHealthService::CACHE_LAST_BOOTSTRAP_FAILURE.'.2',
            $health->scopedKey(ContentProjectQueueHealthService::CACHE_LAST_BOOTSTRAP_FAILURE, 2),
        );
        self::assertSame(
            ContentProjectQueueHealthService::CACHE_LAST_SUCCESS,
            $health->scopedKey(ContentProjectQueueHealthService::CACHE_LAST_SUCCESS, null),
        );
    }

    public function test_candidate_resolver_never_queries_legacy_table(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingConnectionCandidateResolver::class))->getFileName(),
        );

        self::assertStringNotContainsString('SeoDatabaseConnection::query()', $src);
        self::assertStringNotContainsString("hasTable('seo_database_connections')", $src);
        self::assertSame([], (new PublishingConnectionCandidateResolver)->skippedActiveConnections());
        self::assertCount(0, (new PublishingConnectionCandidateResolver)->eligibleForPublishingScan());
    }
}
