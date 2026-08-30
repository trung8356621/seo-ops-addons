<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleListPostTypeFilterContractTest extends TestCase
{
    public function test_page_filter_uses_content_type_not_articles_type(): void
    {
        $source = $this->articleResourceSource();

        self::assertStringContainsString('applyPostTypeFilterScope', $source);
        self::assertStringContainsString('META_CONTENT_TYPE', $source);
        self::assertStringContainsString('NativeContentTypeMapper::nativeSlugsFor', $source);
        self::assertStringNotContainsString("where('articles.type'", $source);
        self::assertStringNotContainsString("whereIn('articles.type'", $source);
        self::assertDoesNotMatchRegularExpression(
            '/applyPostTypeFilterScope[\s\S]{0,800}articles\.type/',
            $source,
        );
    }

    public function test_filter_options_use_canonical_lowercase_keys(): void
    {
        $source = $this->articleResourceSource();

        self::assertStringContainsString("ContentType::Post->value", $source);
        self::assertStringContainsString("ContentType::Page->value", $source);
        self::assertStringContainsString("ContentType::Product->value", $source);
        self::assertStringNotContainsString("'Page' =>", $source);
        self::assertStringNotContainsString("'Post' =>", $source);
    }

    public function test_native_slugs_for_page_include_page_and_landing_page(): void
    {
        $slugs = NativeContentTypeMapper::nativeSlugsFor(ContentType::Page);

        self::assertContains('page', $slugs);
        self::assertContains('landing_page', $slugs);
        self::assertNotContains('post', $slugs);
        self::assertNotContains('product', $slugs);
    }

    public function test_list_articles_exposes_filtered_total_from_paginator(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/ListArticles.php',
        );

        self::assertStringContainsString('getFilteredTableResultsCount', $source);
        self::assertStringContainsString('TOOLBAR_START', $source);
        self::assertStringContainsString('filtered_results', $source);
        self::assertStringContainsString('->total()', $source);
        self::assertStringNotContainsString('articles.type', $source);
    }

    public function test_transitional_fallback_matches_missing_content_type_via_wp_post_type(): void
    {
        $source = $this->articleResourceSource();

        self::assertStringContainsString('whereDoesntHave', $source);
        self::assertStringContainsString('META_WP_POST_TYPE', $source);
        self::assertStringContainsString('whereIn(\'meta_value\', $nativeSlugs)', $source);
    }

    private function articleResourceSource(): string
    {
        return (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );
    }
}
