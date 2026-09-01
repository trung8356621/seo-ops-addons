<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInboundLinkGraphService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Auto-load WP content, selection Insert Link, orphan-page Link Assistant contracts.
 */
final class ArticleEditorWpAutoLoadInsertLinkOrphanTest extends TestCase
{
    public function test_orphan_is_inbound_zero_not_outbound_zero(): void
    {
        self::assertTrue(ArticleInboundLinkGraphService::isOrphan(0));
        self::assertFalse(ArticleInboundLinkGraphService::isOrphan(1));
    }

    public function test_orphan_query_counts_inbound_without_requiring_keyword(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInboundLinkGraphService::class))->getFileName(),
        );

        self::assertStringContainsString("whereIn('target_article_id'", $src);
        self::assertStringContainsString('SeoLinkMapType::Internal', $src);
        self::assertStringNotContainsString("whereNotNull('keyword_id')", $src);
        self::assertStringContainsString('pickOrphanSuggestions', $src);
        self::assertStringNotContainsString("'action' => 'open_source'", $src);
        self::assertStringNotContainsString('inbound_opportunities', $src);
        self::assertStringNotContainsString('inbound_sources', $src);
        self::assertStringNotContainsString('outbound_count', $src);
    }

    public function test_orphan_subset_skips_self_non_orphan_and_duplicates(): void
    {
        $currentId = 10;
        $rows = [
            ['target_article_id' => 10, 'href' => 'https://example.test/self', 'text' => 'self'],
            ['target_article_id' => 20, 'href' => 'https://example.test/orphan', 'text' => 'orphan'],
            ['target_article_id' => 20, 'href' => 'https://example.test/orphan', 'text' => 'dup'],
            ['target_article_id' => 30, 'href' => 'https://example.test/linked', 'text' => 'has inbound'],
            ['target_article_id' => 40, 'href' => 'https://other.test/ext', 'text' => 'external-shaped'],
        ];
        $orphanIds = [20 => true, 40 => true];
        $picked = ArticleInboundLinkGraphService::selectOrphanRows($rows, $currentId, $orphanIds, 8);

        self::assertCount(2, $picked);
        self::assertSame(20, $picked[0]['target_article_id']);
        self::assertSame(40, $picked[1]['target_article_id']);
        foreach ($picked as $row) {
            self::assertNotSame($currentId, (int) $row['target_article_id']);
        }
    }

    public function test_non_orphan_inbound_including_null_keyword_is_excluded(): void
    {
        $picked = ArticleInboundLinkGraphService::selectOrphanRows(
            [
                ['target_article_id' => 50, 'href' => '/b', 'text' => 'b'],
            ],
            1,
            [],
            8,
        );
        self::assertSame([], $picked);
    }

    public function test_links_payload_exposes_orphan_suggestions_not_inbound_ui(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorLinksPayloadService.php',
        );
        self::assertStringContainsString('ArticleInboundLinkGraphService', $src);
        self::assertStringContainsString("'suggested_orphan_links'", $src);
        self::assertStringNotContainsString("'link_graph'", $src);
        self::assertStringNotContainsString('inbound_sources', $src);
    }

    public function test_editor_open_uses_local_sources_then_livewire_fetch(): void
    {
        $edit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString('resolveEditorHtmlLocalOnly', $edit);
        self::assertStringContainsString('loadWpEditorHtmlFromWordPress', $edit);
        self::assertStringContainsString('body_unchanged', $edit);
        self::assertStringContainsString('wordpressPermalink', $edit);
        self::assertStringContainsString('getObservedWordPressPermalink', $edit);

        $hydrate = $this->methodBody(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
            'hydrateArticleState',
        );
        self::assertStringNotContainsString('resolveSlug', $hydrate);

        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useWpEditorContentAutoLoad.js',
        );
        self::assertStringContainsString("wire.call('loadWpEditorHtmlFromWordPress')", $js);
        self::assertStringContainsString('skipNextAutosave', $js);
    }

    public function test_insert_link_reuses_create_link_on_selection(): void
    {
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorLinksAndSnippets.js',
        );
        self::assertStringContainsString("insertMode === 'selection'", $hook);
        self::assertStringContainsString("executeEditorCommand('create_link'", $hook);
        self::assertStringContainsString('links_insert_need_selection', $hook);
        self::assertStringNotContainsString('replace(/<a', $hook);

        $sidebar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx',
        );
        self::assertStringContainsString("insert_mode: 'selection'", $sidebar);
        self::assertStringContainsString('links_insert_link', $sidebar);
        self::assertStringContainsString('links_orphan_pages_title', $sidebar);
        self::assertStringContainsString('links_internal_title', $sidebar);
        self::assertStringNotContainsString('links_inbound_title', $sidebar);
        self::assertStringNotContainsString('Outbound links', $sidebar);
        self::assertStringNotContainsString('Inbound links', $sidebar);
        self::assertStringNotContainsString('links_open_source_article', $sidebar);

        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );
        self::assertStringContainsString("links_internal_title: 'Internal Links ({count})'", $i18n);
        self::assertStringContainsString("links_orphan_pages_title: 'Orphan Pages ({count})'", $i18n);
        self::assertStringNotContainsString("links_internal_title: 'Outbound links: {count}'", $i18n);

        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/linkCommands.js',
        );
        self::assertStringContainsString('unsetLink().setLink(attrs)', $cmd);
    }

    public function test_core_bootstrap_prefers_stored_wordpress_permalink(): void
    {
        $boot = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('wordpressPermalink', $boot);
        self::assertStringContainsString('wordpressPermalink || constructedUrl', $boot);
    }

    public function test_seo_payload_does_not_fetch_wp_to_build_url_identity(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorSeoPayloadService.php',
        );
        self::assertStringNotContainsString('resolveSlug(', $src);
        self::assertStringContainsString("meta_key === 'wp_permalink'", $src);
        self::assertStringContainsString("trim((string) (\$article->slug ?? ''))", $src);
    }

    public function test_happy_path_blocker_copy_removed(): void
    {
        $blocker = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleContentSyncRequiredBlocker.jsx',
        );
        self::assertStringNotContainsString('content_sync_required_title', $blocker);
        self::assertStringNotContainsString('content_sync_required_action', $blocker);
        self::assertStringContainsString('content_wp_loading', $blocker);
        self::assertStringContainsString('content_wp_load_failed', $blocker);
    }

    private function methodBody(string $path, string $method): string
    {
        $src = (string) file_get_contents($path);
        self::assertTrue(preg_match('/function '.$method.'\(/', $src) === 1);
        $start = (int) strpos($src, 'function '.$method.'(');
        $chunk = substr($src, $start, 4000);

        return $chunk;
    }
}
