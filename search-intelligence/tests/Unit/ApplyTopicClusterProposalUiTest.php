<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Tests\TestCase;

final class ApplyTopicClusterProposalUiTest extends TestCase
{
    public function test_preview_modal_exposes_single_cluster_apply_action(): void
    {
        $modal = (string) file_get_contents(
            dirname(__DIR__, 2).'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/cluster-proposal-preview-modal.blade.php'
        );
        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );

        self::assertStringContainsString('topic_apply_action', $modal);
        self::assertStringContainsString('openApplyProposalConfirm', $modal);
        self::assertStringContainsString('confirmApplyProposal', $modal);
        self::assertStringContainsString('topic_apply_needs_review_title', $modal);
        self::assertStringContainsString('AppliesTopicClusterProposals', $page);
        self::assertStringContainsString('toggleReadyProposalSelection', $modal);
        self::assertStringContainsString('openBatchApplySelectedConfirm', $modal);
        self::assertStringContainsString('openBatchApplyAllReadyConfirm', $modal);
        self::assertStringContainsString('topic_batch_apply_all_ready_action', $modal);
        self::assertStringContainsString('AppliesTopicClusterProposalBatches', $page);
        self::assertStringNotContainsString('Apply selected', $modal);
        self::assertStringNotContainsString('Apply all', $modal);
    }
}
