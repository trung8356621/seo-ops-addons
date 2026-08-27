<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Tests\TestCase;

final class ApplyTopicClusterProposalUiTest extends TestCase
{
    public function test_topic_cluster_index_no_longer_wires_proposal_preview_ui(): void
    {
        $index = (string) file_get_contents(
            dirname(__DIR__, 2).'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );

        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/cluster-proposal-preview-modal.blade.php'
        );
        self::assertStringNotContainsString('openClusterProposalPreview', $index);
        self::assertStringNotContainsString('cluster-proposal-preview-modal', $index);
        self::assertStringNotContainsString('topic_proposal_open', $index);
        self::assertStringNotContainsString('AppliesTopicClusterProposals', $page);
        self::assertStringNotContainsString('AppliesTopicClusterProposalBatches', $page);
        self::assertStringContainsString('ReclustersTopicClusters', $page);
        self::assertStringContainsString('topic_recluster_action', $index);
        self::assertStringContainsString('pollReclusterResult', $index);
    }
}
