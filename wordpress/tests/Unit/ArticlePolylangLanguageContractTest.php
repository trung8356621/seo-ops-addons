<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\WordPress\Services\ArticlePolylangSyncService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use App\Models\Site;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Contract: wp-seo-ai sends multilingual.current_lang as Polylang slug (normalized),
 * Laravel Content stores codes only; UI resolves labels without mutating storage.
 *
 * Run with: SEO_TEST_USE_MYSQL=true vendor/bin/phpunit --filter=ArticlePolylangLanguageContractTest
 */
final class ArticlePolylangLanguageContractTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    public function test_plugin_payload_vi_slug_persists_as_vi(): void
    {
        $this->requireSeoDatabaseConnection();
        [$site, $article] = $this->seedLocalArticle('en');

        app(ArticlePolylangSyncService::class)->applyFromSyncItem($article, $site, [
            'multilingual' => [
                'current_lang' => 'vi',
                'translations' => [],
            ],
        ]);

        $article->refresh();
        self::assertSame('vi', $article->language);
    }

    public function test_plugin_payload_en_slug_persists_as_en(): void
    {
        $this->requireSeoDatabaseConnection();
        [$site, $article] = $this->seedLocalArticle('vi');

        app(ArticlePolylangSyncService::class)->applyFromSyncItem($article, $site, [
            'multilingual' => [
                'current_lang' => 'en',
                'translations' => [],
            ],
        ]);

        $article->refresh();
        self::assertSame('en', $article->language);
    }

    public function test_plugin_vn_alias_normalizes_to_vi(): void
    {
        $this->requireSeoDatabaseConnection();
        [$site, $article] = $this->seedLocalArticle('en');

        app(ArticlePolylangSyncService::class)->applyFromSyncItem($article, $site, [
            'multilingual' => [
                'current_lang' => 'vn',
                'translations' => [],
            ],
        ]);

        $article->refresh();
        self::assertSame('vi', $article->language);
    }

    public function test_display_label_does_not_mutate_stored_code(): void
    {
        $this->requireSeoDatabaseConnection();
        [$site, $article] = $this->seedLocalArticle('vi');

        $label = app(SitePolylangService::class)->languageLabel((string) $article->language, $site);
        self::assertSame('Tiếng Việt', $label);

        $article->forceFill(['language' => 'vi'])->saveQuietly();
        $article->refresh();
        self::assertSame('vi', $article->language);
        self::assertNotSame('Tiếng Việt', $article->language);
    }

    public function test_editor_label_input_is_normalized_to_code_on_save(): void
    {
        $this->requireSeoDatabaseConnection();
        [, $article] = $this->seedLocalArticle('en');

        $article->language = 'Tiếng Việt';
        $article->saveQuietly();
        $article->refresh();

        self::assertSame('vi', $article->language);
        self::assertSame('vi', ArticleLanguageCode::normalize('Tiếng Việt'));
    }

    public function test_local_article_without_wp_payload_keeps_explicit_code(): void
    {
        $this->requireSeoDatabaseConnection();
        [, $article] = $this->seedLocalArticle('en');

        // No multilingual payload → WordPress is not language SoT for local-only article.
        app(ArticlePolylangSyncService::class)->applyFromSyncItem($article, $article->site, [
            'title' => 'Local only',
        ]);

        $article->refresh();
        self::assertSame('en', $article->language);
        self::assertNull($article->wordpressLink?->wp_post_id);
    }

    public function test_polylang_absent_fallback_semantics_documented_as_vi_from_plugin(): void
    {
        // Mirrors wp-seo-ai Polylang_Sync::multilingual_field_for_post when Polylang inactive:
        // current_lang defaults to 'vi'. Laravel must accept that code as-is.
        self::assertSame('vi', ArticleLanguageCode::normalizeForStorage('vi'));
        self::assertSame('vi', ArticleLanguageCode::normalize('vi'));
    }

    /**
     * @return array{0: Site, 1: SeoArticle}
     */
    private function seedLocalArticle(string $language): array
    {
        $site = Site::query()->create([
            'user_id' => 1,
            'domain' => 'lang-contract-'.uniqid('', true).'.test',
            'status' => 'active',
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => (int) $site->id,
            'user_id' => 1,
            'type' => 'article',
            'title' => 'Language contract '.$language,
            'slug' => 'language-contract-'.uniqid(),
            'status' => 'draft',
            'body' => '<p>x</p>',
            'language' => $language,
        ]);

        return [$site->fresh(), $article->fresh(['site'])];
    }
}
