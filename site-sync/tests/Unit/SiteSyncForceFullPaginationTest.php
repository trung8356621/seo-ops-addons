<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncRunCompletionGuard;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use Omnichannel\Addons\SiteSync\Support\SiteSyncImportContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: force_full must not complete at ~1024/1524 records on large sites.
 */
final class SiteSyncForceFullPaginationTest extends TestCase
{
    public function test_per_job_batch_constants_are_small_not_41_or_20(): void
    {
        self::assertSame(8, SiteSyncSchema::SNAPSHOT_BATCHES_PER_JOB);
        self::assertSame(4, SiteSyncSchema::CATALOG_BATCHES_PER_JOB);
        self::assertLessThan(41, SiteSyncSchema::SNAPSHOT_BATCHES_PER_JOB);
        self::assertLessThan(20, SiteSyncSchema::CATALOG_BATCHES_PER_JOB);
    }

    public function test_step_runner_defers_snapshot_when_has_more(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString("'__defer_step' => true", $src);
        self::assertStringContainsString('SNAPSHOT_BATCHES_PER_JOB', $src);
        self::assertStringNotContainsString('$loops < 40', $src);
        self::assertStringNotContainsString('while ($loops < 20)', $src);
        self::assertStringContainsString('site_sync.chunk_completed', $src);
        self::assertStringContainsString('SiteSyncRunCompletionGuard::validate', $src);
    }

    public function test_checkpoint_catalog_does_not_add_fetched_and_reconciled(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString("\$runCounters['reconciled']", $src);
        self::assertStringNotContainsString("\$totals['articles'] ?? 0) + (int) (\$runCounters['checked']", $src);
    }

    public function test_completion_guard_rejects_has_more_batches(): void
    {
        $run = new SeoSiteSyncRun([
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'meta' => ['force_full' => true, 'has_more_batches' => true, 'snapshot_exhausted' => false],
            'counters' => ['total_to_check' => 8078, 'fetched' => 1024],
        ]);

        $result = SiteSyncRunCompletionGuard::validate($run);
        self::assertFalse($result['ok']);
        self::assertSame('snapshot_has_more=true', $result['reason']);
    }

    public function test_completion_guard_rejects_3048_of_8078_scenario(): void
    {
        $run = new SeoSiteSyncRun([
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'meta' => [
                'force_full' => true,
                'snapshot_exhausted' => true,
                'batch_ids' => [],
            ],
            'counters' => [
                'total_to_check' => 8078,
                'fetched' => 1524,
                'reconciled' => 1524,
                'checked' => 3048,
            ],
        ]);

        $result = SiteSyncRunCompletionGuard::validate($run);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('reconciled 1524/8078', (string) $result['reason']);
    }

    public function test_completion_guard_accepts_fully_synced_force_full(): void
    {
        $run = new SeoSiteSyncRun([
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'meta' => [
                'force_full' => true,
                'snapshot_exhausted' => true,
                'batch_ids' => [],
            ],
            'counters' => [
                'total_to_check' => 8078,
                'fetched' => 8078,
                'reconciled' => 8078,
            ],
        ]);

        $result = SiteSyncRunCompletionGuard::validate($run);
        self::assertTrue($result['ok']);
    }

    public function test_completion_guard_allows_delta_mode_without_force_full_checks(): void
    {
        $run = new SeoSiteSyncRun([
            'mode' => SiteSyncSchema::MODE_DELTA,
            'meta' => [],
            'counters' => ['fetched' => 3, 'total_to_check' => 0],
        ]);

        $result = SiteSyncRunCompletionGuard::validate($run);
        self::assertTrue($result['ok']);
    }

    public function test_import_context_is_reentrant(): void
    {
        $depth = 0;
        SiteSyncImportContext::run(static function () use (&$depth): void {
            self::assertTrue(SiteSyncImportContext::isActive());
            $depth = 1;
            SiteSyncImportContext::run(static function () use (&$depth): void {
                self::assertTrue(SiteSyncImportContext::isActive());
                $depth = 2;
            });
            self::assertTrue(SiteSyncImportContext::isActive());
        });
        self::assertSame(2, $depth);
        self::assertFalse(SiteSyncImportContext::isActive());
    }

    public function test_large_site_job_count_estimate(): void
    {
        $total = 8078;
        $pageSize = 25;
        $batches = (int) ceil($total / $pageSize);
        $snapshotJobs = (int) ceil($batches / SiteSyncSchema::SNAPSHOT_BATCHES_PER_JOB);
        $catalogJobs = (int) ceil($batches / SiteSyncSchema::CATALOG_BATCHES_PER_JOB);

        self::assertSame(324, $batches);
        self::assertSame(41, $snapshotJobs);
        self::assertSame(81, $catalogJobs);
        self::assertGreaterThan(1, $snapshotJobs, '8078 posts must require multiple continuation jobs');
    }

    public function test_site_health_no_longer_queries_articles_last_synced_at(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService::class))->getFileName()
        );
        self::assertStringContainsString('resolveLastSyncAt', $src);
        self::assertStringNotContainsString("SeoArticle::query()->where('site_id', \$siteId)->max('last_synced_at')", $src);
        self::assertStringContainsString('wordpress_article_links', $src);
    }

    public function test_document_version_skips_during_site_sync_import(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService::class))->getFileName()
        );
        self::assertStringContainsString('SiteSyncImportContext::isActive()', $src);
    }
}
