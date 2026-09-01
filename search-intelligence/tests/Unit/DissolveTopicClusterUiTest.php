<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class DissolveTopicClusterUiTest extends TestCase
{
    public function test_cluster_list_has_dissolve_row_action(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        $partial = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/dissolve-cluster-row-action.blade.php',
        ));

        self::assertStringContainsString('dissolve-cluster-row-action', $index);
        self::assertStringContainsString('topic_dissolve_action', $partial);
        self::assertStringContainsString('openDissolveConfirm(', $partial);
        self::assertStringContainsString('Illuminate\\Support\\Js::from', $partial);
        self::assertStringContainsString('seo-content-ai::content-project-action-menu-shell', $partial);
        self::assertStringContainsString('content-project-ops-styles', $index);
        self::assertStringContainsString('cluster-index-row__actions', $index);
        self::assertStringContainsString('clusterDataEpoch', $index);
        self::assertStringContainsString('canDissolveCluster', $index);
        self::assertStringContainsString('dissolve-cluster-modal', $index);
    }

    public function test_cluster_detail_has_dissolve_header_action(): void
    {
        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));

        self::assertStringContainsString('topic_dissolve_action', $detail);
        self::assertStringContainsString('openDissolveConfirm(', $detail);
        self::assertStringContainsString('Illuminate\\Support\\Js::from', $detail);
        self::assertStringContainsString('canDissolveCluster', $detail);
        self::assertStringContainsString('dissolve-cluster-modal', $detail);
        // Modal must stay mounted after dissolve clears detail members.
        self::assertMatchesRegularExpression(
            '/@endif\s*@include\([\'"]seo-content-ai::filament\.resources\.keywords\.pages\.partials\.dissolve-cluster-modal/s',
            $detail,
        );
    }

    public function test_index_title_is_not_navigation_link(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));

        self::assertStringContainsString('cluster-index-row__title', $index);
        self::assertStringContainsString('@dblclick.prevent="startEdit()"', $index);
        self::assertStringContainsString('saveClusterCanonicalFromIndex', $index);
        self::assertStringContainsString('renameSeq', $index);
        self::assertStringContainsString('applyRowPatch', $index);
        self::assertStringNotContainsString('await $wire.$refresh()', $index);
        self::assertStringContainsString('topic_view_cluster', $index);
        self::assertStringContainsString('topic-index-detail-btn', $index);
        self::assertStringNotContainsString('class="topic-index-link"', $index);
    }

    public function test_both_pages_use_same_domain_service(): void
    {
        $clustersPage = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $detailPage = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        $trait = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopicClusters.php');

        self::assertStringContainsString('DissolvesTopicClusters', $clustersPage);
        self::assertStringContainsString('DissolvesTopicClusters', $detailPage);
        self::assertStringContainsString('DissolveTopicClusterService', $trait);
        self::assertStringContainsString('TopicClusterDissolveSideEffects', (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/DissolveTopicClusterService.php'));
        self::assertStringContainsString('confirmDissolveCluster', $trait);
        self::assertStringContainsString('TopicClusterDerivedCleanup', (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/DissolveTopicClusterService.php'));
    }

    public function test_confirmation_modal_is_required_before_mutation(): void
    {
        $modal = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/dissolve-cluster-modal.blade.php',
        ));

        self::assertStringContainsString('dissolveClusterKey', $modal);
        self::assertStringContainsString('confirmDissolveCluster', $modal);
        self::assertStringContainsString('cancelDissolveConfirm', $modal);
        self::assertStringContainsString('topic_dissolve_confirm', $modal);
        self::assertStringContainsString('topic_dissolve_cancel', $modal);

        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        self::assertStringNotContainsString('wire:click="confirmDissolveCluster"', $index);
    }

    public function test_list_dissolve_does_not_force_full_page_reload(): void
    {
        $trait = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopicClusters.php');
        $detailPage = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');

        self::assertStringContainsString('shouldRedirectAfterDissolve', $trait);
        self::assertStringContainsString('return true', $detailPage);
        self::assertStringNotContainsString('return true', $trait);
    }

    public function test_unauthorized_users_cannot_dissolve_from_ui_or_backend(): void
    {
        $trait = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopicClusters.php');

        self::assertStringContainsString('canMutateInSeoPanel', $trait);
        self::assertStringContainsString('canAccessSite', $trait);
        self::assertStringContainsString('canDissolveCluster', $trait);
        self::assertStringContainsString('if (! $this->canDissolveCluster())', $trait);
    }
}
