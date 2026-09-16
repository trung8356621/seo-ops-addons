<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class EditArticlePermalinkDisplayContractTest extends TestCase
{
    public function test_edit_article_exposes_candidate_and_observed_permalink_helpers(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        self::assertStringContainsString('function getCandidatePermalink(): string', $source);
        self::assertStringContainsString('function getCandidatePermalinkTemplate(): string', $source);
        self::assertStringContainsString('function getObservedWordPressPermalink(): string', $source);
        self::assertStringContainsString('candidatePermalink', $source);
        self::assertStringContainsString("'wordpress_permalink' => trim(\$this->getObservedWordPressPermalink())", $source);
        self::assertStringContainsString("meta_key', 'wp_permalink'", $source);
        self::assertStringContainsString('ArticleWordPressPostType::normalizeEditorInput', $source);
    }

    public function test_edit_article_blade_renders_dual_permalink_rows_with_expected_label(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
        );

        self::assertStringContainsString('getCandidatePermalink()', $blade);
        self::assertStringContainsString('getObservedWordPressPermalink()', $blade);
        self::assertStringContainsString('Đường dẫn dự kiến:', $blade);
        self::assertStringContainsString('Đường dẫn WP:', $blade);
        self::assertStringContainsString('data-permalink-template', $blade);
        self::assertStringContainsString('data-seo-wp-permalink-row', $blade);
        self::assertStringContainsString('Chưa có cấu hình permalink từ WordPress', $blade);
        self::assertStringContainsString('Chưa đồng bộ', $blade);
    }

    public function test_js_permalink_patch_uses_template_and_keeps_wp_row_read_only(): void
    {
        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );

        self::assertStringContainsString('export function buildPermalinkFromTemplate', $api);
        self::assertStringContainsString('export function normalizePermalinkForCompare', $api);
        self::assertStringContainsString('syncWordPressPermalinkRowVisibility', $api);
        self::assertStringContainsString('data-seo-wp-permalink-row', $api);
        self::assertStringContainsString('permalink_template', $api);
        self::assertStringContainsString('buildPermalinkFromTemplate(template, slug)', $api);
        self::assertStringContainsString('Chưa đồng bộ', $api);
    }

    public function test_builder_uses_article_wordpress_post_type_not_legacy_type_column(): void
    {
        $builder = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Support/WordPressPermalinkBuilder.php',
        );

        self::assertStringContainsString('ArticleWordPressPostType::resolve', $builder);
        self::assertStringContainsString('function candidatePermalink', $builder);
        self::assertStringContainsString('function candidateTemplate', $builder);
        self::assertStringNotContainsString('SeoProjectTask::normalizePostType', $builder);
        self::assertDoesNotMatchRegularExpression(
            '/\$article->type\s*\?\?/',
            $builder,
        );
    }
}
