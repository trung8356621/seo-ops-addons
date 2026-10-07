<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Tests\TestCase;

final class TopicGroupingReclusterModalTest extends TestCase
{
    public function test_toolbar_and_simple_confirm_modal_contract(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );

        self::assertStringContainsString('openReclusterModal', $blade);
        self::assertStringContainsString('topic_ai_history_link', $blade);
        self::assertStringContainsString('beginConfirmAiAudit', $blade);
        self::assertStringContainsString('topic-recluster-modal-title', $blade);
        self::assertStringContainsString('Xóa cấu trúc Topic cũ và tách lại từ đầu', $blade);
        self::assertStringContainsString('fullResetTopicStructure', $blade);
        self::assertStringContainsString("reclusterModalStep === 'configure'", $blade);
        self::assertStringContainsString('wire:click="startTopicAnalysis"', $blade);
        self::assertStringContainsString('Tách lại chủ đề', $blade);
        self::assertStringContainsString('Đang chuẩn bị áp dụng', $blade);

        // Manual proposal review UX removed.
        self::assertStringNotContainsString('Xem đề xuất', $blade);
        self::assertStringNotContainsString('Xem thay đổi', $blade);
        self::assertStringNotContainsString('Có đề xuất tách lại chủ đề đang chờ xử lý', $blade);
        self::assertStringNotContainsString('openProposalPreview', $blade);
        self::assertStringNotContainsString('beginConfirmApplyProposal', $blade);
        self::assertStringNotContainsString('applyProposal', $blade);
        self::assertStringNotContainsString('discardProposal', $blade);
        self::assertStringNotContainsString('HARD BLOCK', $blade);
        self::assertStringNotContainsString('Provider hiện tại: legacy', $blade);

        self::assertStringContainsString('selectedGroupingProvider', $concern);
        self::assertStringContainsString('TopicGroupingProviderMode::SEMANTIC_HTTP', $concern);
        self::assertStringContainsString('RECLUSTER_STEP_PREPARING_APPLY', $concern);
        self::assertStringNotContainsString('openProposalPreview', $concern);
        self::assertStringNotContainsString('RECLUSTER_STEP_PREVIEW', $concern);
    }

    public function test_checkbox_visible_when_global_provider_is_legacy(): void
    {
        config([
            'semantic.topic_provider' => 'legacy',
            'semantic.topic_grouping_provider' => 'legacy',
        ]);
        putenv('TOPIC_GROUPING_PROVIDER=legacy');
        self::assertTrue(TopicGroupingProviderMode::isLegacy());

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('fullResetTopicStructure', $blade);
        self::assertStringContainsString('Xóa cấu trúc Topic cũ và tách lại từ đầu', $blade);
        self::assertStringNotContainsString('Provider hiện tại: legacy', $blade);

        $concern = new class
        {
            use \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;

            public function resolveKeywordWorkspaceSiteId(): ?int
            {
                return 4;
            }
        };
        self::assertTrue($concern->canUseFullResetRebuildMode());
        self::assertSame(TopicGroupingProviderMode::SEMANTIC_HTTP, $concern->selectedGroupingProvider());
    }

    public function test_unchecked_and_checked_dispatch_modes_always_semantic(): void
    {
        config(['semantic.topic_provider' => 'legacy']);
        self::assertTrue(TopicGroupingProviderMode::isLegacy());

        $concern = new class
        {
            use \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;

            public function resolveKeywordWorkspaceSiteId(): ?int
            {
                return 4;
            }
        };

        $concern->fullResetTopicStructure = false;
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $concern->selectedRebuildMode());
        self::assertSame(TopicGroupingProviderMode::SEMANTIC_HTTP, $concern->selectedGroupingProvider());

        $concern->fullResetTopicStructure = true;
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $concern->selectedRebuildMode());
        self::assertSame(TopicGroupingProviderMode::SEMANTIC_HTTP, $concern->selectedGroupingProvider());

        $concernSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringContainsString(
            'ReclusterSiteTopicsJob::dispatch($siteId, $version, $rebuildMode, $provider)',
            $concernSrc,
        );
        self::assertStringContainsString(
            'ReclusterSiteTopicsJob::dispatchSync($siteId, $version, $rebuildMode, $provider)',
            $concernSrc,
        );
    }

    public function test_job_uses_explicit_provider_not_global_config(): void
    {
        config(['semantic.topic_provider' => 'legacy']);
        self::assertTrue(TopicGroupingProviderMode::isLegacy());

        $job = new ReclusterSiteTopicsJob(
            4,
            'v',
            TopicGroupingRebuildMode::FULL_RESET,
            TopicGroupingProviderMode::SEMANTIC_HTTP,
        );
        self::assertSame(TopicGroupingProviderMode::SEMANTIC_HTTP, $job->provider);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $job->rebuildMode);

        $preserve = new ReclusterSiteTopicsJob(
            4,
            'v',
            TopicGroupingRebuildMode::PRESERVE_EXISTING,
            TopicGroupingProviderMode::SEMANTIC_HTTP,
        );
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $preserve->rebuildMode);
        self::assertSame(TopicGroupingProviderMode::SEMANTIC_HTTP, $preserve->provider);

        $jobSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php'
        );
        // Branch on job.provider — never TopicGroupingProviderMode::isSemanticHttp() for path select.
        self::assertStringContainsString('isSemanticProvider($this->provider)', $jobSrc);
        self::assertStringNotContainsString('TopicGroupingProviderMode::isSemanticHttp()', $jobSrc);
        self::assertStringContainsString('analyzeSite($this->siteId, $rebuildMode, $provider)', $jobSrc);
        self::assertStringNotContainsString('Config::set', $jobSrc);
        self::assertStringNotContainsString("config(['semantic.topic_provider'", $jobSrc);
    }

    public function test_job_semantic_failure_has_no_legacy_fallback_contract(): void
    {
        $jobSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php'
        );
        self::assertStringContainsString('handleSemanticAnalyzeAndApply', $jobSrc);
        // After semantic path entry, no call into TopicReclusterService::recluster.
        $semanticPos = strpos($jobSrc, 'handleSemanticAnalyzeAndApply');
        $legacyReclusterPos = strpos($jobSrc, '$recluster->recluster(');
        self::assertNotFalse($semanticPos);
        self::assertNotFalse($legacyReclusterPos);
        self::assertLessThan($legacyReclusterPos, $semanticPos);
        // Semantic failure returns before any legacy recluster.
        self::assertStringContainsString('if ($run->status !== TopicGroupingRunStatus::PROPOSAL_READY)', $jobSrc);
        self::assertStringContainsString('return;', $jobSrc);
    }

    public function test_legacy_provider_class_still_exists(): void
    {
        self::assertTrue(class_exists(LegacyTopicGroupingProvider::class));
        self::assertSame('legacy-topic-grouping', LegacyTopicGroupingProvider::KEY);
        self::assertSame(TopicGroupingProviderMode::LEGACY, TopicGroupingProviderMode::normalize('legacy'));
    }

    public function test_page_load_does_not_auto_apply_historical_proposal(): void
    {
        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringNotContainsString('->apply(', $concern);
        self::assertStringNotContainsString('TopicGroupingApplyService', $concern);
        self::assertStringContainsString('hydrateReclusterModalStepFromState', $concern);
    }

    public function test_focus_article_wording_retained_without_preview_cta(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('Focus Article', $blade);
        self::assertStringNotContainsString('Focus Topics dissolved', $blade);
        self::assertStringNotContainsString('openProposalPreview', $blade);
    }
}
