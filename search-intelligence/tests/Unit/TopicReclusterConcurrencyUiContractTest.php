<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class TopicReclusterConcurrencyUiContractTest extends TestCase
{
    public function test_index_and_detail_restore_lock_and_poll(): void
    {
        $indexPage = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $detailPage = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        $trait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersTopicClusters.php');
        $dissolve = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopicClusters.php');
        $itemActions = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordItemActions.php');

        self::assertStringContainsString('syncReclusterStateFromCache', $indexPage);
        self::assertStringContainsString('syncReclusterStateFromCache', $detailPage);
        self::assertStringContainsString('onKeywordWorkspaceSiteFilterChanged', $indexPage);
        self::assertMatchesRegularExpression(
            '/function onKeywordWorkspaceSiteFilterChanged\(\): void\s*\{[^}]*syncReclusterStateFromCache/s',
            $indexPage,
        );
        self::assertStringContainsString('use ReclustersTopicClusters', $detailPage);
        self::assertStringContainsString('TopicClusterReclusterState', $trait);
        self::assertStringContainsString('isTopicMutationLocked', $trait);
        self::assertStringContainsString('hasTopicClusterMutationPermission', $trait);
        self::assertStringContainsString('navigate: false', $trait);
        self::assertStringContainsString('wasPolling', $trait);
        self::assertStringContainsString('closeTopicMutationUiBeforeRecluster', $trait);

        self::assertStringContainsString('TopicClusterReclusterState::assertMutationAllowed', $indexPage);
        self::assertStringContainsString('TopicClusterReclusterState::assertMutationAllowed', $detailPage);
        self::assertStringContainsString('TopicClusterReclusterState::assertMutationAllowed', $dissolve);
        self::assertStringContainsString('TopicClusterReclusterState::assertMutationAllowed', $itemActions);
        self::assertStringContainsString('isMutationLocked', $dissolve);

        self::assertStringNotContainsString('resetPage()', $trait);
    }

    public function test_lock_banner_and_poll_rendered_on_index_and_detail(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));

        self::assertStringContainsString('topic_recluster_lock_banner_title', $index);
        self::assertStringContainsString('topic_recluster_lock_banner_body', $index);
        self::assertStringContainsString('wire:poll.5s="pollReclusterResult"', $index);
        self::assertStringContainsString('hasTopicClusterMutationPermission', $index);
        self::assertStringContainsString('topicMutationsLocked', $index);

        self::assertStringContainsString('topic_recluster_lock_banner_title', $detail);
        self::assertStringContainsString('wire:poll.5s="pollReclusterResult"', $detail);
        self::assertStringContainsString('topicMutationsLocked', $detail);
    }

    public function test_state_service_exists_and_uses_job_cache_key(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/TopicClusterReclusterState.php');

        self::assertStringContainsString('ReclusterTopicClustersJob::resultCacheKey', $service);
        self::assertStringContainsString('STALE_RUNNING_SECONDS = 900', $service);
        self::assertStringContainsString('assertMutationAllowed', $service);
        self::assertStringContainsString('isMutationLocked', $service);
        self::assertStringNotContainsString('topic_cluster_recluster_lock:', $service);
    }
}
