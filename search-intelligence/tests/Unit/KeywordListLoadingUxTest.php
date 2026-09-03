<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordListLoadingUxTest extends TestCase
{
    public function test_keyword_list_reuses_article_style_table_overlay(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/list-keywords.blade.php',
        ));
        $shell = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/components/list-table-loading-shell.blade.php',
        ));

        self::assertStringContainsString('list-table-loading-shell', $blade);
        self::assertStringContainsString('preset="filament-table"', $blade);
        self::assertStringContainsString('onKeywordWorkspaceSiteFilterChanged', $blade);
        self::assertStringContainsString('applyDictionaryStatFilter', $blade);
        self::assertStringContainsString('keywordLanguageFilter', $blade);
        self::assertStringContainsString('partials.keyword-classification-summary', $blade);

        self::assertStringContainsString('is-table-loading', $shell);
        self::assertStringContainsString("Livewire.hook('commit'", $shell);
        self::assertStringContainsString('article_list.table_loading', $shell);
        self::assertStringContainsString('pointer-events: none', $shell);
        self::assertStringContainsString('gotoPage', $shell);
        self::assertStringContainsString('onDomainContextChanged', $shell);
        self::assertStringContainsString('/^poll/i', $shell);
    }

    public function test_topic_cluster_and_related_lists_use_the_same_overlay(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));
        $anchor = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/anchor-text-audit-workspace.blade.php',
        ));

        self::assertStringContainsString('list-table-loading-shell', $index);
        self::assertStringContainsString('clusterSearch', $index);
        self::assertStringContainsString('clusterSort', $index);
        self::assertMatchesRegularExpression('/list-table-loading-shell[^>]*targets="[^"]*clusterSearch/', $index);
        self::assertDoesNotMatchRegularExpression('/list-table-loading-shell[^>]*targets="[^"]*quickCreateCluster/', $index);

        self::assertStringContainsString('list-table-loading-shell', $detail);

        self::assertStringContainsString('list-table-loading-shell', $anchor);
        self::assertStringContainsString('setTriageFilter', $anchor);
        self::assertStringNotContainsString('link-triage-loading-overlay', $anchor);
        self::assertStringNotContainsString('hideKeywordFromSeo', $index);
    }

    public function test_anchor_audit_eager_loads_wp_post_id_via_wordpress_link(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/AnchorTextAuditWorkspace.php');

        self::assertStringNotContainsString('sourceArticle:id,site_id,title,slug,wp_post_id', $source);
        self::assertStringContainsString('sourceArticle:id,site_id,title,slug', $source);
        self::assertStringContainsString('sourceArticle.wordpressLink:id,article_id,wp_post_id', $source);
    }
}
