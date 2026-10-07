<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\RebuildTopicsFromKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromKeywordGroupMaterializer;
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

        self::assertStringContainsString('rebuildModalOpen = true', $blade);
        self::assertStringContainsString('topic_ai_history_link', $blade);
        self::assertStringContainsString('beginConfirmAiAudit', $blade);
        self::assertStringContainsString('topic-rebuild-modal-title', $blade);
        self::assertStringContainsString('topic_rebuild_full_reset_label', $blade);
        self::assertStringContainsString('fullReset', $blade);
        self::assertStringContainsString('$wire.startTopicRebuildFromGroups(fullReset)', $blade);
        self::assertStringNotContainsString(
            'startTopicRebuildFromGroups(fullReset); rebuildModalOpen = false',
            $blade,
        );
        self::assertStringContainsString('submitting', $blade);
        self::assertStringContainsString('topic_recluster_action', $blade);
        self::assertStringNotContainsString('nhóm lại toàn bộ keyword', $blade);
        self::assertStringNotContainsString('wire:click="startTopicAnalysis"', $blade);
        self::assertSame(1, substr_count($blade, 'wire:click="openReclusterModal"'));

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

        self::assertStringContainsString('startTopicRebuildFromGroups', $concern);
        self::assertStringContainsString('RebuildTopicsFromKeywordGroupsJob', $concern);
        self::assertStringContainsString('TopicFromKeywordGroupMaterializer::ALGORITHM', $concern);
        self::assertStringNotContainsString('ReclusterSiteTopicsJob::dispatch(', $concern);
        self::assertStringNotContainsString('TopicGroupingProviderMode::SEMANTIC_HTTP', $concern);
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
        self::assertStringContainsString('fullReset', $blade);
        self::assertStringContainsString('topic_rebuild_full_reset_label', $blade);
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
        self::assertSame(TopicFromKeywordGroupMaterializer::ALGORITHM, $concern->selectedGroupingProvider());
    }

    public function test_unchecked_and_checked_dispatch_modes_from_groups(): void
    {
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

        $concern->fullResetTopicStructure = true;
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $concern->selectedRebuildMode());

        $concernSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringContainsString(
            'RebuildTopicsFromKeywordGroupsJob::dispatch($siteId, $rebuildMode)',
            $concernSrc,
        );
        self::assertStringContainsString(
            'RebuildTopicsFromKeywordGroupsJob::dispatchSync($siteId, $rebuildMode)',
            $concernSrc,
        );
    }

    public function test_legacy_semantic_job_still_exists_for_compat(): void
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

        $fromGroups = new RebuildTopicsFromKeywordGroupsJob(4, TopicGroupingRebuildMode::PRESERVE_EXISTING);
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $fromGroups->rebuildMode);
        self::assertSame('seo', $fromGroups->queue);
    }

    public function test_job_semantic_failure_has_no_legacy_fallback_contract(): void
    {
        $jobSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php'
        );
        self::assertStringContainsString('handleSemanticAnalyzeAndApply', $jobSrc);
        $semanticPos = strpos($jobSrc, 'handleSemanticAnalyzeAndApply');
        $legacyReclusterPos = strpos($jobSrc, '$recluster->recluster(');
        self::assertNotFalse($semanticPos);
        self::assertNotFalse($legacyReclusterPos);
        self::assertLessThan($legacyReclusterPos, $semanticPos);
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
        $vi = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/lang/vi/filament.php'
        );
        self::assertStringContainsString('topic_rebuild_bullet_focus', $blade);
        self::assertStringContainsString('Focus Article', $vi);
        self::assertStringNotContainsString('Focus Topics dissolved', $blade);
        self::assertStringNotContainsString('openProposalPreview', $blade);
    }
}
