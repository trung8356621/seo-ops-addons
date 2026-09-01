<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Same phrase on Site A and Site B must not overwrite each other's focus article.
 */
final class KeywordFocusAttachSiteScopeContractTest extends TestCase
{
    public function test_site_scoped_main_article_meta_key(): void
    {
        self::assertSame('site.2.main_article_id', KeywordMetaKey::siteMainArticleId(2));
        self::assertSame('main_article_id', KeywordMetaKey::MainArticleId->value);
    }

    public function test_attach_writes_site_scoped_meta_and_rejects_mismatch(): void
    {
        $attachSrc = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordFocusAttach::class))->getFileName(),
        );
        $repoSrc = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMetaRepository::class))->getFileName(),
        );

        self::assertStringContainsString('setMainArticleIdForSite', $attachSrc);
        self::assertStringContainsString('seo.cross_site_relation_rejected', $attachSrc);
        self::assertStringContainsString('keyword_site_article_site_mismatch', $attachSrc);
        self::assertStringContainsString('function getMainArticleIdForSite', $repoSrc);
        self::assertStringContainsString('function setMainArticleIdForSite', $repoSrc);
        self::assertStringContainsString('siteMainArticleId', $repoSrc);
    }

    public function test_presenter_filters_linked_articles_by_site(): void
    {
        $presenter = (string) file_get_contents(
            dirname(__DIR__, 3).'/search-foundation/src/Support/KeywordLinkDetailPanelPresenter.php'
        );

        self::assertStringContainsString('mainArticlesForSite', $presenter);
        self::assertStringContainsString('buildLinkedSourceArticles(Keyword $keyword, ?int $siteId', $presenter);
        self::assertStringContainsString('$sourceSiteId !== $siteId', $presenter);
    }
}
