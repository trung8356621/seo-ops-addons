<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Tests\TestCase;

/**
 * Internal-link candidates must be gated to the current article's language — never cross-language.
 */
final class ArticleInternalLinkLanguageGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(int $siteId, string $title, string $slug, string $language, int $wpPostId): SeoArticle
    {
        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
            'status' => 'published',
            'type' => 'article',
            'language' => $language,
            'wp_post_id' => $wpPostId,
        ]);

        $article->articleMetas()->create([
            'meta_key' => 'wp_permalink',
            'meta_value' => 'https://language-gate.test/'.$slug.'/',
        ]);

        return $article->fresh() ?? $article;
    }

    public function test_current_vi_article_only_matches_vi_candidates(): void
    {
        $site = Site::query()->create([
            'domain' => 'language-gate.test',
            'status' => 'active',
        ]);

        $current = $this->makeArticle((int) $site->id, 'Balo thời trang hiện tại', 'balo-thoi-trang-hien-tai', 'vi', 100);
        $viTarget = $this->makeArticle((int) $site->id, 'Balo thời trang', 'balo-thoi-trang', 'vi', 101);
        $this->makeArticle((int) $site->id, 'Balo thời trang', 'fashion-backpack', 'en', 102);

        $retriever = app(ArticleLinkSuggestionCandidateRetriever::class);

        $ranked = $retriever->searchRanked($current, 'Balo thời trang');
        self::assertNotEmpty($ranked);
        foreach ($ranked as $row) {
            self::assertNotSame(102, (int) $row['id']);
        }
        self::assertSame((int) $viTarget->id, (int) $ranked[0]['id']);

        $resolved = $retriever->resolveBestForAnchors($current, [
            ['keyword_id' => 1, 'phrase' => 'Balo thời trang'],
        ]);
        self::assertArrayHasKey(1, $resolved);
        self::assertSame((int) $viTarget->id, (int) $resolved[1]['target_article_id']);

        $searchService = app(ArticleInternalLinkSearchService::class);
        $fallback = $searchService->search((int) $site->id, (int) $current->id, 'Balo thời trang');
        self::assertNotEmpty($fallback);
        foreach ($fallback as $row) {
            self::assertNotSame(102, (int) $row['id']);
        }
    }

    public function test_current_en_article_only_matches_en_candidates(): void
    {
        $site = Site::query()->create([
            'domain' => 'language-gate-en.test',
            'status' => 'active',
        ]);

        $current = $this->makeArticle((int) $site->id, 'Current fashion backpack', 'current-fashion-backpack', 'en', 200);
        $this->makeArticle((int) $site->id, 'Balo thời trang', 'balo-thoi-trang-2', 'vi', 201);
        $enTarget = $this->makeArticle((int) $site->id, 'Fashion backpack', 'fashion-backpack-2', 'en', 202);

        $retriever = app(ArticleLinkSuggestionCandidateRetriever::class);

        $ranked = $retriever->searchRanked($current, 'Fashion backpack');
        self::assertNotEmpty($ranked);
        foreach ($ranked as $row) {
            self::assertNotSame(201, (int) $row['id']);
        }
        self::assertSame((int) $enTarget->id, (int) $ranked[0]['id']);
    }

    public function test_no_same_language_destination_returns_no_result(): void
    {
        $site = Site::query()->create([
            'domain' => 'language-gate-empty.test',
            'status' => 'active',
        ]);

        $current = $this->makeArticle((int) $site->id, 'Balo học sinh', 'balo-hoc-sinh', 'vi', 300);
        $this->makeArticle((int) $site->id, 'Balo học sinh', 'balo-hoc-sinh-en', 'en', 301);

        $retriever = app(ArticleLinkSuggestionCandidateRetriever::class);

        self::assertSame([], $retriever->searchRanked($current, 'Balo học sinh'));
        self::assertSame([], $retriever->resolveBestForAnchors($current, [
            ['keyword_id' => 1, 'phrase' => 'Balo học sinh'],
        ]));
    }
}
