<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class EditArticlePermalinkDisplayContractTest extends TestCase
{
    public function test_edit_article_exposes_observed_wp_permalink_helpers(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        self::assertStringContainsString('function getObservedWordPressPermalink(): string', $source);
        self::assertStringContainsString('function permalinksAreEquivalent(string $left, string $right): bool', $source);
        self::assertStringContainsString('function normalizePermalinkForCompare(string $url): string', $source);
        self::assertStringContainsString("'wordpress_permalink' => trim(\$this->getObservedWordPressPermalink())", $source);
        self::assertStringContainsString("meta_key', 'wp_permalink'", $source);
    }

    public function test_edit_article_blade_renders_dual_permalink_rows(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
        );

        self::assertStringContainsString('getObservedWordPressPermalink()', $blade);
        self::assertStringContainsString('Đường dẫn WP:', $blade);
        self::assertStringContainsString('data-seo-wp-permalink-row', $blade);
        self::assertStringContainsString('data-seo-wp-permalink-url', $blade);
        self::assertStringContainsString('data-wordpress-permalink', $blade);
        self::assertStringContainsString('data-seo-permalink-url', $blade);
        self::assertStringContainsString('permalinksAreEquivalent', $blade);
    }

    public function test_js_permalink_patch_keeps_wp_row_read_only_and_compares_normalized(): void
    {
        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );

        self::assertStringContainsString('export function normalizePermalinkForCompare', $api);
        self::assertStringContainsString('export function permalinksAreEquivalent', $api);
        self::assertStringContainsString('syncWordPressPermalinkRowVisibility', $api);
        self::assertStringContainsString('data-seo-wp-permalink-row', $api);
        self::assertStringContainsString('wordpress_permalink', $api);
        self::assertStringContainsString('buildPermalinkDisplayUrl(base, slug, suffix)', $api);
    }

    public function test_normalize_permalink_for_compare_matches_php_semantics_via_reflection_source(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        self::assertStringContainsString('return rtrim(mb_strtolower(trim($url)), \'/\');', $source);

        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString(".trim().toLowerCase().replace(/\\/+$/, '')", $js);
    }
}
