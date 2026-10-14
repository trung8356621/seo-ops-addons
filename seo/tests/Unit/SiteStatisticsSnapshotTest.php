<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Seo\Jobs\RebuildSiteStatisticsSnapshotJob;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsRebuilder;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsRefresh;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsSnapshotStore;
use RuntimeException;
use Tests\TestCase;

final class SiteStatisticsSnapshotTest extends TestCase
{
    public function test_successful_sync_requests_refresh_and_failure_does_not(): void
    {
        Bus::fake();
        $refresh = app(SiteStatisticsRefresh::class);

        $refresh->afterSuccessfulSync(7, 'completed');
        $refresh->afterSuccessfulSync(7, 'completed_with_warnings');
        $refresh->afterSuccessfulSync(7, 'failed');
        $refresh->afterSuccessfulSync(7, 'needs_attention');

        Bus::assertDispatchedTimes(RebuildSiteStatisticsSnapshotJob::class, 1);
        self::assertSame(2, app(SiteStatisticsSnapshotStore::class)->generation(7));
    }

    public function test_archive_requests_refresh_once_per_coalesced_site(): void
    {
        Bus::fake();
        $refresh = app(SiteStatisticsRefresh::class);
        $refresh->afterSuccessfulArchive(7);
        $refresh->afterSuccessfulArchive(7);
        $refresh->afterSuccessfulArchive(6);

        Bus::assertDispatched(RebuildSiteStatisticsSnapshotJob::class, function (RebuildSiteStatisticsSnapshotJob $job): bool {
            return $job->siteId === 7 || $job->siteId === 6;
        });
        Bus::assertDispatchedTimes(RebuildSiteStatisticsSnapshotJob::class, 2);
    }

    public function test_rolled_back_transaction_does_not_request_refresh(): void
    {
        Bus::fake();

        try {
            DB::transaction(function (): void {
                app(SiteStatisticsRefresh::class)->afterSuccessfulArchive(7);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Bus::assertNothingDispatched();
        self::assertSame(0, app(SiteStatisticsSnapshotStore::class)->generation(7));
    }

    public function test_failed_rebuild_keeps_previous_snapshot_and_stale_build_does_not_win(): void
    {
        $store = app(SiteStatisticsSnapshotStore::class);
        $store->put(7, $this->payload(7, 1, 'kept'));

        $rebuilder = $this->getMockBuilder(SiteStatisticsRebuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $rebuilder->method('build')->willThrowException(new RuntimeException('rebuild failed'));
        $this->app->instance(SiteStatisticsRebuilder::class, $rebuilder);

        try {
            app(RebuildSiteStatisticsSnapshotJob::class, ['siteId' => 7])->handle($store, $rebuilder);
            self::fail('expected rebuild failure');
        } catch (RuntimeException $e) {
            self::assertSame('rebuild failed', $e->getMessage());
        }

        self::assertSame('kept', $store->get(7)['statistics']['marker'] ?? null);

        $calls = 0;
        $rebuilder = $this->getMockBuilder(SiteStatisticsRebuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $rebuilder->method('build')->willReturnCallback(function (int $siteId, int $generation) use (&$calls, $store): array {
            $calls++;
            if ($calls === 1) {
                $store->bumpGeneration($siteId);

                return $this->payload($siteId, $generation, 'stale');
            }

            return $this->payload($siteId, $generation, 'fresh');
        });

        (new RebuildSiteStatisticsSnapshotJob(7))->handle($store, $rebuilder);

        self::assertSame('fresh', $store->get(7)['statistics']['marker'] ?? null);
        self::assertNotSame('stale', $store->get(7)['statistics']['marker'] ?? null);
    }

    public function test_page_reader_does_not_recompute_statistics(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 3).'/search-foundation/src/Filament/Resources/DomainResource/Pages/GeneralDomain.php',
        );
        $scoring = $this->methodBody($page, 'getScoringStatistics');
        $distribution = $this->methodBody($page, 'getScoreDistribution');
        $sync = $this->methodBody($page, 'getSyncStatistics');

        self::assertStringNotContainsString('domainOverview()', $scoring);
        self::assertStringNotContainsString('domainOverview()', $distribution);
        self::assertStringNotContainsString('domainOverview()', $sync);
        self::assertStringContainsString("statistic('scoring')", $scoring);
    }

    public function test_refresh_is_wired_only_to_sync_success_and_archive(): void
    {
        $root = dirname(__DIR__, 3);
        $syncV3 = (string) file_get_contents($root.'/site-sync/src/Services/Orchestration/RunSiteSyncV3Orchestrator.php');
        $stepRunner = (string) file_get_contents($root.'/site-sync/src/Services/Orchestration/SiteSyncStepRunner.php');
        $projectArchive = (string) file_get_contents($root.'/content-projects/src/Services/ArchiveContentProjectService.php');
        $itemArchive = (string) file_get_contents($root.'/content-projects/src/Services/SeoProjectArchiveService.php');
        $articleReview = (string) file_get_contents($root.'/content/src/Services/ArticleReviewService.php');
        $focus = (string) file_get_contents($root.'/seo/src/Services/FocusKeywordCoverageService.php');

        self::assertStringContainsString('afterSuccessfulSync', $syncV3);
        self::assertStringContainsString('afterSuccessfulSync', $stepRunner);
        self::assertStringNotContainsString('afterSuccessfulSync', $this->methodBody($syncV3, 'failRun'));
        self::assertStringContainsString('afterSuccessfulArchive', $projectArchive);
        self::assertStringContainsString('afterSuccessfulArchive', $itemArchive);
        self::assertStringNotContainsString('SiteStatisticsRefresh', $articleReview);
        self::assertStringNotContainsString('SiteStatisticsRefresh', $focus);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $siteId, int $generation, string $marker): array
    {
        return [
            'schema_version' => SiteStatisticsSnapshotStore::SCHEMA_VERSION,
            'site_id' => $siteId,
            'generation' => $generation,
            'generated_at' => now()->toIso8601String(),
            'statistics' => ['marker' => $marker],
        ];
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        self::assertNotFalse($start);
        $next = strlen($source);
        foreach (["\n    public function ", "\n    private function ", "\n    protected function "] as $needle) {
            $at = strpos($source, $needle, $start + 10);
            if ($at !== false && $at < $next) {
                $next = $at;
            }
        }

        return substr($source, $start, $next - $start);
    }
}
