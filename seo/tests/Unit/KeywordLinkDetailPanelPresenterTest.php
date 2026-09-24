<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Keyword Detail sidebar article-role separation.
 * Proves Focus / Linked / Internal Links presentation boundaries without SEO DB.
 */
final class KeywordLinkDetailPanelPresenterTest extends TestCase
{
    private function presenterSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/../search-foundation/src/Support/KeywordLinkDetailPanelPresenter.php'
        );
    }

    private function drawerBladeSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2)
            .'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-dictionary-drawer-content.blade.php'
        );
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/public function '.$method.'\s*\(.*?\{/s';
        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail('Method '.$method.' not found');
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        $this->fail('Unclosed method body for '.$method);
    }

    public function test_focus_article_returned_separately_via_build_focus_article(): void
    {
        $source = $this->presenterSource();
        $this->assertStringContainsString(
            'function buildFocusArticle(Keyword $keyword, ?int $siteId = null): ?array',
            $source,
        );
        $focusBody = $this->methodBody($source, 'buildFocusArticle');
        $this->assertStringContainsString('mainArticlesForSite($siteId)', $focusBody);
        $this->assertStringContainsString('presentLinkedSourceArticle(', $focusBody);
        $this->assertStringContainsString('true,', $focusBody);
    }

    public function test_linked_source_articles_do_not_append_focus_article(): void
    {
        $linkedBody = $this->methodBody($this->presenterSource(), 'buildLinkedSourceArticles');
        $this->assertStringNotContainsString('foreach ($focusArticles as $focusArticle)', $linkedBody);
        $this->assertStringContainsString('$focusArticleId > 0 && $articleId === $focusArticleId', $linkedBody);
        $this->assertStringContainsString('continue;', $linkedBody);
        $this->assertStringContainsString('false,', $linkedBody);
        $this->assertStringContainsString('mainArticlesForSite($siteId)', $linkedBody);
    }

    public function test_focus_duplicate_excluded_from_linked_but_edges_stay_in_build_items(): void
    {
        $source = $this->presenterSource();
        $linkedBody = $this->methodBody($source, 'buildLinkedSourceArticles');
        $itemsBody = $this->methodBody($source, 'buildItems');

        $this->assertStringContainsString('$articleId === $focusArticleId', $linkedBody);
        $this->assertStringNotContainsString('$focusArticleId', $itemsBody);
        $this->assertStringContainsString('sourceArticle', $itemsBody);
        $this->assertStringContainsString('targetArticle', $itemsBody);
        $this->assertStringContainsString("'source_title'", $itemsBody);
        $this->assertStringContainsString("'target_url'", $itemsBody);
    }

    public function test_no_focus_returns_null_and_drawer_shows_empty_placeholder(): void
    {
        $focusBody = $this->methodBody($this->presenterSource(), 'buildFocusArticle');
        $blade = $this->drawerBladeSource();

        $this->assertStringContainsString('return null;', $focusBody);
        $this->assertStringContainsString('$focusArticle === null', $blade);
        $this->assertStringContainsString('keyword-dictionary-drawer__empty', $blade);
    }

    public function test_site_scope_preserved_and_cross_site_focus_does_not_leak(): void
    {
        $source = $this->presenterSource();
        $focusBody = $this->methodBody($source, 'buildFocusArticle');
        $linkedBody = $this->methodBody($source, 'buildLinkedSourceArticles');
        $itemsBody = $this->methodBody($source, 'buildItems');

        $this->assertStringContainsString('resolveViewSiteId($keyword, $siteId)', $focusBody);
        $this->assertStringContainsString('if ($siteId <= 0)', $focusBody);
        $this->assertStringContainsString('mainArticlesForSite($siteId)', $focusBody);
        $this->assertStringContainsString('(int) ($sourceArticle->site_id ?? 0) !== $siteId', $linkedBody);
        $this->assertStringContainsString('$sourceSiteId !== $siteId', $itemsBody);
    }

    public function test_shared_drawer_renders_three_headings_in_order(): void
    {
        $blade = $this->drawerBladeSource();

        $this->assertStringContainsString('buildFocusArticle(', $blade);
        $this->assertStringContainsString('buildLinkedSourceArticles(', $blade);
        $this->assertStringContainsString('buildItems(', $blade);
        $this->assertStringContainsString('drawer_focus_article_heading', $blade);
        $this->assertStringContainsString('drawer_linked_articles_heading', $blade);
        $this->assertStringContainsString('drawer_internal_links_heading', $blade);

        $focusPos = strpos($blade, 'drawer_focus_article_heading');
        $linkedPos = strpos($blade, 'drawer_linked_articles_heading');
        $internalPos = strpos($blade, 'drawer_internal_links_heading');
        $this->assertNotFalse($focusPos);
        $this->assertNotFalse($linkedPos);
        $this->assertNotFalse($internalPos);
        $this->assertLessThan($linkedPos, $focusPos);
        $this->assertLessThan($internalPos, $linkedPos);
    }

    public function test_linked_articles_section_no_longer_renders_focus_badge(): void
    {
        $blade = $this->drawerBladeSource();
        $linkedSectionStart = strpos($blade, 'drawer_linked_articles_heading');
        $internalSectionStart = strpos($blade, 'drawer_internal_links_heading');
        $this->assertNotFalse($linkedSectionStart);
        $this->assertNotFalse($internalSectionStart);

        $linkedSection = substr($blade, $linkedSectionStart, $internalSectionStart - $linkedSectionStart);
        $this->assertStringNotContainsString("\$article['is_focus']", $linkedSection);
        $this->assertStringContainsString('stat_active', $linkedSection);

        $focusSectionStart = strpos($blade, 'drawer_focus_article_heading');
        $this->assertNotFalse($focusSectionStart);
        $focusSection = substr($blade, $focusSectionStart, $linkedSectionStart - $focusSectionStart);
        $this->assertStringContainsString('focus_short', $focusSection);
    }

    public function test_mini_stat_counts_come_from_presenter_collections(): void
    {
        $blade = $this->drawerBladeSource();
        $source = $this->presenterSource();

        $this->assertStringContainsString('$focusArticleCount = $focusArticle !== null ? 1 : 0', $blade);
        $this->assertStringContainsString('$linkedArticleCount = $linkedArticles->count()', $blade);
        $this->assertStringContainsString('$internalLinkCount = $internalLinks->count()', $blade);
        $this->assertStringContainsString('focus_articles', $blade);
        $this->assertStringNotContainsString('linked_articles_count', $blade);
        $this->assertStringNotContainsString('site_links_count', $blade);
        $this->assertStringContainsString('function counts(Keyword $keyword, ?int $siteId = null): array', $source);
        $this->assertStringContainsString("'linked_article_count'", $source);
        $this->assertStringContainsString("'internal_link_count'", $source);
        $this->assertStringContainsString("'focus_article_count'", $source);

        $focusStatPos = strpos($blade, 'focus_articles');
        $linkedStatPos = strpos($blade, "'seo-content-ai::filament.keyword.linked_articles'");
        $this->assertNotFalse($focusStatPos);
        $this->assertNotFalse($linkedStatPos);
        $this->assertLessThan($linkedStatPos, $focusStatPos);
    }

    public function test_linked_source_articles_are_distinct_by_source_article_id(): void
    {
        $linkedBody = $this->methodBody($this->presenterSource(), 'buildLinkedSourceArticles');
        $this->assertStringContainsString('isset($seen[$articleId])', $linkedBody);
        $this->assertStringContainsString('$seen[$articleId] = true', $linkedBody);
    }

    public function test_internal_link_count_filters_link_type_internal_only(): void
    {
        $countsBody = $this->methodBody($this->presenterSource(), 'counts');
        $this->assertStringContainsString('SeoLinkMapType::Internal->value', $countsBody);
        $this->assertStringContainsString('buildItems(', $countsBody);
        $this->assertStringContainsString('buildLinkedSourceArticles(', $countsBody);
    }

    public function test_lang_keys_exist_for_focus_heading(): void
    {
        $en = (string) file_get_contents(
            dirname(__DIR__, 2).'/../seo-content-ai-compat/lang/en/filament.php'
        );
        $vi = (string) file_get_contents(
            dirname(__DIR__, 2).'/../seo-content-ai-compat/lang/vi/filament.php'
        );

        $this->assertStringContainsString("'drawer_focus_article_heading' => 'Focus article'", $en);
        $this->assertStringContainsString("'drawer_focus_article_heading' => 'Bài viết Focus'", $vi);
    }
}
