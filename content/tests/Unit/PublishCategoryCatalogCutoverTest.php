<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Support\PublishCategoryOptionsAssembler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class PublishCategoryCatalogCutoverTest extends TestCase
{
    public function test_publish_selector_uses_wordpress_catalog_not_articles_table(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(EditArticle::class))->getFileName()
        );

        self::assertStringContainsString('PublishCategoryOptionsAssembler', $source);
        self::assertStringContainsString('PublishingTaxonomySelectionFilter', $source);
        self::assertStringNotContainsString("whereIn('type', ['category', 'product_category'])", $source);
        self::assertStringNotContainsString('buildHierarchicalPublishCategoryOptions', $source);
        self::assertStringContainsString("['meta_key' => 'category_ids']", $source);
        self::assertStringContainsString("['meta_key' => 'wp_parent_id']", $source);
        self::assertStringContainsString('isTaxonomyEntityForPublish', $source);
        self::assertStringContainsString('persistTaxonomyParentId', $source);
    }

    public function test_assembler_does_not_query_seo_articles(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(PublishCategoryOptionsAssembler::class))->getFileName()
        );

        self::assertStringNotContainsString('SeoArticle', $source);
        self::assertStringContainsString('PublishingTaxonomyCatalog', $source);
        self::assertStringContainsString('TAXONOMY_PRODUCT_CAT', $source);
        self::assertStringContainsString('TAXONOMY_CATEGORY', $source);
    }

    public function test_taxonomy_entity_term_editor_contract_still_present(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php'
        );

        self::assertStringContainsString("'product_category', 'product_cat' => 'product_cat'", $source);
    }
}
