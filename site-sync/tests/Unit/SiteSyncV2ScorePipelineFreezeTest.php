<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\QueueMissingSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueAllSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RetryFailedSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV2ScorePipelineFreezeTest extends TestCase
{
    public function test_orchestrator_includes_score_missing_before_finalize(): void
    {
        $steps = SiteSyncSchema::ORCHESTRATOR_STEPS;
        self::assertContains('score_missing_articles', $steps);
        self::assertSame('finalize', $steps[array_key_last($steps)]);
        self::assertLessThan(
            array_search('finalize', $steps, true),
            array_search('score_missing_articles', $steps, true),
        );
        self::assertCount(9, $steps);
    }

    public function test_step_runner_has_score_missing_method(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString("'score_missing_articles'", $src);
        self::assertStringContainsString('queueMissingOrStaleForSite', $src);
        self::assertStringContainsString('__defer_step', $src);
        self::assertStringContainsString("\$checkpoint['deferred'] = true", $src);
        self::assertStringContainsString('$polls >= 6', $src);
        self::assertStringContainsString('completed_with_warnings', $src);
    }

    public function test_wordpress_sync_import_scores_each_item_with_php_engine(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SyncDomainContentService::class))->getFileName());

        self::assertStringContainsString('scoreSyncedItemWithPhp', $src);
        self::assertStringContainsString('analyzeFromSyncItem', $src);
        self::assertStringContainsString('markCompleted', $src);
        self::assertMatchesRegularExpression(
            '/syncSeoMetaFromWordPress\(\$article, \$item\);[\s\S]*?scoreSyncedItemWithPhp\(\$article, \$item\);/',
            $src,
        );
    }

    public function test_scoring_service_exposes_missing_or_stale(): void
    {
        self::assertTrue(method_exists(SeoArticleScoringQueueService::class, 'queueMissingOrStaleForSite'));
        self::assertTrue(method_exists(SeoArticleScoringQueueService::class, 'queueMissingForSite'));
        self::assertTrue(method_exists(SeoArticleScoringQueueService::class, 'queueAllForSite'));
    }

    public function test_commands_registered_on_bus(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName());
        self::assertStringContainsString('QueueMissingSeoScoresCommand::class', $src);
        self::assertStringContainsString('RequeueAllSeoScoresCommand::class', $src);
        self::assertStringContainsString('RetryFailedSeoScoresCommand::class', $src);
    }

    public function test_requeue_all_requires_confirmation_flag(): void
    {
        $cmd = new RequeueAllSeoScoresCommand(siteId: 1, confirmed: false);
        self::assertFalse($cmd->confirmed);
        self::assertSame('site.score_requeue_all', $cmd->name());
        self::assertSame('site.score_missing', (new QueueMissingSeoScoresCommand(1))->name());
        self::assertSame('site.score_retry_failed', (new RetryFailedSeoScoresCommand(1))->name());
    }

    public function test_main_ui_hides_score_missing_button(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php');
        $src = (string) file_get_contents($blade);
        self::assertStringNotContainsString('runQueueMissingSeoScoringAction', $src);
        self::assertStringContainsString('Chấm lại toàn bộ bài viết', $src);
        self::assertStringContainsString('@if ($showTest)', $src);
        self::assertStringContainsString('runRequeueAllSeoScoringAction', $src);
    }

    public function test_domain_ui_scoring_actions_use_command_bus(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('QueueMissingSeoScoresCommand', $src);
        self::assertStringContainsString('RequeueAllSeoScoresCommand', $src);
        self::assertStringContainsString('RetryFailedSeoScoresCommand', $src);
        self::assertStringContainsString('dispatchSiteSyncBus(new RequeueAllSeoScoresCommand', $src);
    }

    public function test_pending_processing_raw_labels_removed_from_general_domain(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/general-domain.blade.php');
        $src = (string) file_get_contents($blade);
        self::assertStringNotContainsString('seo_scoring_pending', $src);
        self::assertStringNotContainsString('seo_scoring_processing', $src);
        self::assertStringContainsString('siteSyncScoringContext', $src);
    }

    public function test_presenter_has_scoring_context_helper(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName());
        self::assertStringContainsString('scoringContextMessage', $src);
        self::assertStringContainsString('Chờ hoàn tất đồng bộ dữ liệu', $src);
        self::assertStringContainsString('Đang chuẩn bị chấm SEO', $src);
        self::assertStringContainsString('Chấm SEO đang chờ worker xử lý', $src);
        self::assertStringContainsString('isRunStuck', $src);
        self::assertStringContainsString('score_missing_articles', $src);
    }

    public function test_domain_save_does_not_trigger_scoring(): void
    {
        $path = (new ReflectionClass(PersistsDomainPromptContext::class))->getFileName();
        self::assertNotFalse($path);
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('SeoArticleScoringQueueService', $src);
        self::assertStringNotContainsString('QueueMissingSeoScoresCommand', $src);
        self::assertStringNotContainsString('score_missing', $src);
    }

    public function test_architecture_freeze_step_count_updated(): void
    {
        // Companion to SiteSyncV2ArchitectureFreezeTest — keep single source of truth here after +1 step.
        self::assertContains('score_missing_articles', SiteSyncSchema::ORCHESTRATOR_STEPS);
    }
}
