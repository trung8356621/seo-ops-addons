<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Tests\TestCase;

final class TopicGroupingReclusterModalTest extends TestCase
{
    public function test_toolbar_and_modal_contract_no_permanent_checkbox(): void
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
        self::assertStringContainsString('Xem thay đổi', $blade);
        self::assertStringContainsString('Có đề xuất tách lại chủ đề đang chờ xử lý', $blade);

        // Checkbox only inside modal configure step — not permanent toolbar companion.
        self::assertStringContainsString("reclusterModalStep === 'configure'", $blade);
        self::assertStringContainsString('fullResetTopicStructure', $blade);
        self::assertStringContainsString('Xóa cấu trúc Topic cũ và tách lại từ đầu', $blade);

        self::assertStringContainsString('openReclusterModal', $concern);
        self::assertStringContainsString('isSemanticProposalContext', $concern);
        self::assertStringContainsString('persistedRebuildMode', $concern);
        self::assertStringContainsString('RECLUSTER_STEP_PREVIEW', $concern);
        self::assertStringContainsString('topic_grouping.preview.exception', $concern);
    }

    public function test_full_reset_job_mode_and_legacy_gate(): void
    {
        $job = new ReclusterSiteTopicsJob(4, 'v', TopicGroupingRebuildMode::FULL_RESET);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $job->rebuildMode);

        config(['semantic.topic_provider' => 'legacy']);
        self::assertTrue(TopicGroupingProviderMode::isLegacy());
        self::assertFalse(TopicGroupingProviderMode::isSemanticHttp());

        config(['semantic.topic_provider' => 'semantic_http']);
        self::assertTrue(TopicGroupingProviderMode::isSemanticHttp());
    }

    public function test_full_reset_wording_avoids_misleading_move_copy(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('membership sẽ được dựng lại', $blade);
        self::assertStringNotContainsString('691 keyword đổi chủ đề', $blade);
    }
}
