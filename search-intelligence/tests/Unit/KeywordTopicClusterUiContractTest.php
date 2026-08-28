<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class KeywordTopicClusterUiContractTest extends TestCase
{
    public function test_workspace_two_redirects_and_new_cluster_pages_exist(): void
    {
        $resource = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource.php');
        self::assertStringContainsString("Pages\\KeywordTopicClusters::route('/clusters')", $resource);
        self::assertStringContainsString("Pages\\KeywordTopicClusterDetail::route('/clusters/{clusterKey}')", $resource);
        self::assertStringContainsString("Pages\\KeywordTopicClusterDetail::route('/clusters/{clusterKey}')", $resource);
        self::assertStringNotContainsString('buildChildrenFilterUrl($parentId)', $resource);

        $legacy = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordWorkspaceTwo.php');
        self::assertStringContainsString("getUrl('clusters')", $legacy);

        $nav = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');
        self::assertStringContainsString("getUrl('clusters')", $nav);
        self::assertStringNotContainsString("getUrl('workspace-2')", $nav);
        self::assertStringNotContainsString('setGlobalSiteId(null)', $nav);
        self::assertStringContainsString('domain-context-changed', $nav);
        self::assertStringContainsString('globalSiteId', $nav);

        $navBlade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-workspace-nav.blade.php',
        ));
        self::assertStringNotContainsString('keyword-workspace-domain-select', $navBlade);
        self::assertStringNotContainsString('domain_filter_all', $navBlade);
    }

    public function test_cluster_views_do_not_render_legacy_pillar_ui(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        self::assertStringContainsString('topic_cluster_title', $index);
        self::assertStringContainsString('cluster-mcp-preview', $index);
        self::assertStringContainsString('cluster-index-row', $index);
        self::assertStringNotContainsString('topic_tab_groups', $index);
        self::assertStringNotContainsString('createCustomGroup', $index);
        self::assertStringNotContainsString('getGroups', $index);
        self::assertStringNotContainsString('Nhóm quy tắc', $index);
        self::assertStringNotContainsString('createPillarDraft', $index);
        self::assertStringNotContainsString('newPillarPhrase', $index);
        self::assertStringNotContainsString('topic_col_dna_count', $index);

        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));
        self::assertStringNotContainsString('topic_group_distribution', $detail);
        self::assertStringContainsString('keyword-item', $detail);
        self::assertStringNotContainsString('Primary keyword', $detail);
    }

    public function test_settings_keywords_has_no_review_reasons_ui(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/pages/seo-settings-keywords.blade.php',
        ));
        self::assertStringNotContainsString('review_reasons_title', $blade);
        self::assertStringNotContainsString('reviewReasonRows', $blade);
        self::assertStringNotContainsString('saveReviewReasons', $blade);

        $page = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Pages/SeoSettingsKeywords.php');
        self::assertStringNotContainsString('saveReviewReasons', $page);
        self::assertStringNotContainsString('KeywordReviewReasonService', $page);
    }

    public function test_sidebar_runtime_override_paths_exist(): void
    {
        $base = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/overrides/filament-panels/components/sidebar/';
        self::assertFileExists($base.'item.blade.php');
        self::assertFileExists($base.'group.blade.php');
        self::assertFileExists($base.'collapsed-flyout.blade.php');
        $provider = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/SeoContentAiServiceProvider.php');
        self::assertStringContainsString("prependNamespace(\n            'filament-panels'", $provider);
        $panel = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/Providers/SeoPanelProvider.php');
        self::assertStringContainsString('seo-sidebar-collapsed', $panel);
    }
}
