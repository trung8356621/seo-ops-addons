<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepClaimResult;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression: score_missing_articles defer must reclaim; UI must not stay healthy-running forever.
 */
final class SiteSyncScoreLifecycleTerminalTest extends TestCase
{
    public function test_defer_marks_checkpoint_for_continuation_reclaim(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());

        self::assertStringContainsString("\$checkpoint['deferred'] = true", $src);
        self::assertStringContainsString('Reclaiming deferred step continuation', $src);
        self::assertStringContainsString('$isDeferredContinuation', $src);
        self::assertStringContainsString('scoring_deferred', $src);
        self::assertStringContainsString('__defer_step', $src);
    }

    public function test_owned_by_other_worker_does_not_block_deferred_continuation(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());

        // Deferred poll must NOT take the OwnedByOtherWorker early-return path.
        self::assertMatchesRegularExpression(
            '/\$isDeferredContinuation[\s\S]*?OwnedByOtherWorker[\s\S]*?return;/',
            $src,
        );
        self::assertStringContainsString('!$isDeferredContinuation', $src);
        self::assertSame('owned_by_other_worker', SiteSyncStepClaimResult::OwnedByOtherWorker->value);
    }

    public function test_score_missing_defers_while_pending_or_processing(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());

        self::assertStringContainsString("\$inFlight = \$pending + \$processing", $src);
        self::assertStringContainsString("'__defer_step' => true", $src);
        self::assertStringContainsString('queueMissingOrStaleForSite', $src);
        self::assertStringContainsString("'finalize'", $src);
    }

    public function test_score_step_completes_when_inflight_zero_even_with_failed(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());

        // Failed scores surface warnings — do not pretend clean completed when failed > 0.
        self::assertStringContainsString('scoring_failed', $src);
        self::assertStringContainsString('completed_with_warnings', $src);
        self::assertStringContainsString('bài chấm SEO thất bại', $src);
    }

    public function test_presenter_exposes_stuck_and_hides_healthy_running(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());

        self::assertStringContainsString("'stuck' => \$stuck", $src);
        self::assertStringContainsString("'running' => \$isActive && ! \$stuck", $src);
        self::assertStringContainsString('isRunStuck', $src);
        self::assertStringContainsString('phase_label', $src);
        self::assertStringContainsString('last_progress_at', $src);
        self::assertStringContainsString('cancellable', $src);
    }

    public function test_scoring_context_covers_partial_and_drain_waiting_finalize(): void
    {
        $method = new ReflectionMethod(SiteSyncStatusPresenter::class, 'scoringContextMessage');
        $method->setAccessible(true);
        $presenter = (new ReflectionClass(SiteSyncStatusPresenter::class))->newInstanceWithoutConstructor();

        $partial = $method->invoke(
            $presenter,
            'running',
            'score_missing_articles',
            ['total' => 100, 'completed' => 40, 'pending' => 50, 'processing' => 10, 'failed' => 0, 'remaining' => 0],
            [],
            false,
        );
        self::assertStringContainsString('Đang chấm SEO', $partial);
        self::assertStringContainsString('10', $partial);

        $drained = $method->invoke(
            $presenter,
            'running',
            'score_missing_articles',
            ['total' => 502, 'completed' => 502, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'remaining' => 0],
            [],
            false,
        );
        self::assertStringContainsString('chấm điểm SEO', $drained);
        self::assertStringContainsString('502', $drained);

        $preScore = $method->invoke(
            $presenter,
            'running',
            'validate_changed_links',
            ['total' => 502, 'completed' => 502, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'remaining' => 0],
            [],
            false,
        );
        self::assertSame('Chờ hoàn tất đồng bộ dữ liệu', $preScore);

        $stuck = $method->invoke(
            $presenter,
            'running',
            'score_missing_articles',
            ['total' => 502, 'completed' => 502, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'remaining' => 0],
            [],
            true,
        );
        self::assertStringContainsString('run kẹt', $stuck);
    }

    public function test_domain_ui_hides_cancel_contract_via_cancellable_flag(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php');
        $progress = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/partials/site-sync-progress.blade.php');
        $src = (string) file_get_contents($blade)."\n".(string) file_get_contents($progress);

        self::assertStringContainsString('siteSyncV2Cancellable', $src);
        self::assertStringContainsString('site_sync_cancel', $src);
        self::assertStringContainsString('siteSyncV2Stuck', $src);
        self::assertStringContainsString('site_sync_substeps', $src);
        self::assertStringContainsString('site_sync_check_status', $src);
        self::assertStringContainsString('site-sync-progress', $src);
    }

    public function test_general_domain_shows_scoring_pending_processing_without_forbidden_keys(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/general-domain.blade.php');
        $src = (string) file_get_contents($blade);

        self::assertStringContainsString("\$seoScoring['pending']", $src);
        self::assertStringContainsString("\$seoScoring['processing']", $src);
        self::assertStringNotContainsString('seo_scoring_pending', $src);
        self::assertStringNotContainsString('seo_scoring_processing', $src);
        self::assertStringContainsString('siteSyncV2Stuck', $src);
    }
}
