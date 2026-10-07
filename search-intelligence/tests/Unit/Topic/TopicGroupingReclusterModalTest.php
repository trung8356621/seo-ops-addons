<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
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
        self::assertStringNotContainsString('membership sẽ được dựng lại', $blade);

        self::assertStringContainsString('openReclusterModal', $concern);
        self::assertStringContainsString('selectedRebuildMode', $concern);
        self::assertStringContainsString('fullResetTopicStructure', $concern);
        self::assertStringContainsString('RECLUSTER_STEP_PREPARING_APPLY', $concern);
        self::assertStringNotContainsString('openProposalPreview', $concern);
        self::assertStringNotContainsString('canPreviewProposal', $concern);
        self::assertStringNotContainsString('canApplyProposal', $concern);
        self::assertStringNotContainsString('applyProposal', $concern);
        self::assertStringNotContainsString('discardProposal', $concern);
        self::assertStringNotContainsString('RECLUSTER_STEP_PREVIEW', $concern);
    }

    public function test_unchecked_and_checked_rebuild_mode_selection(): void
    {
        $concern = new class
        {
            use \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;

            public function resolveKeywordWorkspaceSiteId(): ?int
            {
                return 4;
            }
        };

        config(['semantic.topic_provider' => 'semantic_http']);
        self::assertTrue(TopicGroupingProviderMode::isSemanticHttp());

        $concern->fullResetTopicStructure = false;
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $concern->selectedRebuildMode());

        $concern->fullResetTopicStructure = true;
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $concern->selectedRebuildMode());

        config(['semantic.topic_provider' => 'legacy']);
        $concern->fullResetTopicStructure = true;
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $concern->selectedRebuildMode());
    }

    public function test_job_auto_applies_same_run_after_analyze_contract(): void
    {
        $jobSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php'
        );
        self::assertStringContainsString('handleSemanticAnalyzeAndApply', $jobSrc);
        self::assertStringContainsString('analyzeSite', $jobSrc);
        self::assertStringContainsString('$apply->preview((int) $run->id)', $jobSrc);
        self::assertStringContainsString('$apply->apply((int) $run->id, $preview->plan->planHash)', $jobSrc);
        self::assertStringContainsString('TopicGroupingRunStatus::PROPOSAL_READY', $jobSrc);
        // No user review gate between Analyze and Apply.
        self::assertStringNotContainsString('openProposalPreview', $jobSrc);

        $job = new ReclusterSiteTopicsJob(4, 'v', TopicGroupingRebuildMode::FULL_RESET);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $job->rebuildMode);
    }

    public function test_page_load_does_not_auto_apply_historical_proposal(): void
    {
        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        // Mount/boot must not call apply; only Job/sync recluster path does.
        self::assertStringNotContainsString('->apply(', $concern);
        self::assertStringNotContainsString('TopicGroupingApplyService', $concern);
        self::assertStringContainsString('hydrateReclusterModalStepFromState', $concern);
        self::assertStringContainsString('RECLUSTER_STEP_CONFIGURE', $concern);
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
