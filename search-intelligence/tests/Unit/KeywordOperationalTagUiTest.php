<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordOperationalTagUiTest extends TestCase
{
    public function test_default_table_shows_phrase_tag_cluster_articles_links(): void
    {
        $resource = $this->keywordResourceSource();
        self::assertStringContainsString("ViewColumn::make('keyword_item')", $resource);
        self::assertStringContainsString('keyword-item', $resource);
        self::assertStringNotContainsString("ViewColumn::make('operational_tags')", $resource);
        self::assertStringNotContainsString("TextColumn::make('site_links_count')", $resource);
    }

    public function test_default_table_does_not_show_legacy_human_columns(): void
    {
        $resource = $this->keywordResourceSource();
        self::assertStringNotContainsString("TextColumn::make('type')", $resource);
        self::assertStringNotContainsString("ViewColumn::make('seo_classification')", $resource);
        self::assertStringNotContainsString("TextColumn::make('tag_labels')", $resource);
        self::assertStringNotContainsString("TextColumn::make('quality_flags')", $resource);
        self::assertStringNotContainsString("TextColumn::make('dictionary_status')", $resource);
        self::assertStringNotContainsString("TextColumn::make('site_links_count')", $resource);
        self::assertStringNotContainsString("Filter::make('quality_flags')", $resource);
        self::assertStringNotContainsString("Filter::make('tags_scope')", $resource);
        self::assertStringNotContainsString("BulkAction::make('bulk_tag')", $resource);
        self::assertStringNotContainsString("BulkAction::make('switch_type')", $resource);
        self::assertStringNotContainsString('setTagIds', $resource);
    }

    public function test_primary_filter_is_operational_tags_legacy_type_is_advanced(): void
    {
        $resource = $this->keywordResourceSource();
        self::assertStringContainsString("Filter::make('operational_tags')", $resource);
        self::assertStringContainsString('KeywordTagQuery', $resource);
        self::assertStringContainsString("Filter::make('keyword_type')", $resource);
        self::assertStringContainsString('legacy_type', $resource);
        self::assertStringContainsString("Filter::make('seo_classification')", $resource);
        self::assertStringContainsString('advanced_classification', $resource);
        self::assertStringContainsString("Filter::make('seo_intent')", $resource);
        self::assertStringContainsString("Filter::make('source_kind')", $resource);
        self::assertStringContainsString('KeywordRuleClassifier::intentFilterOptions()', $resource);
        self::assertStringContainsString('KeywordSourceNormalizer::filterOptions()', $resource);
        self::assertStringContainsString('KeywordRuleClassifier::intentLabel', $resource);
        self::assertStringContainsString('KeywordSourceNormalizer::label', $resource);
        self::assertTrue(strpos($resource, "Filter::make('operational_tags')") < strpos($resource, "Filter::make('keyword_type')"));
    }

    public function test_legacy_keyword_type_column_is_not_dropped(): void
    {
        self::assertSame(['normal', 'suggest', 'free'], Keyword::allowedTypes());
        $resource = $this->keywordResourceSource();
        self::assertStringContainsString("return \$query->whereIn('type', \$types);", $resource);
        self::assertStringContainsString('keywordTypeFilterOptions()', $resource);
        self::assertStringNotContainsString('TYPE_SUGGEST', $this->methodSource($resource, 'keywordTypeFilterOptions'));
    }

    /**
     * @return string
     */
    private function methodSource(string $file, string $method): string
    {
        if (! preg_match('/function '.$method.'\([^{]*\{([\s\S]*?)\n    \}/', $file, $m)) {
            self::fail("method {$method} not found");
        }

        return $m[1];
    }

    public function test_keyword_model_still_defines_classification_relation(): void
    {
        self::assertTrue(method_exists(Keyword::class, 'seoClassification'));
    }

    public function test_list_page_removed_manual_tag_actions(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'KeywordResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListKeywords.php');
        self::assertStringNotContainsString('filterTagsAction', $list);
        self::assertStringNotContainsString('manage_tags', $list);
        self::assertStringContainsString('getClassificationSummary', $list);
        self::assertStringContainsString('InteractsWithKeywordItemActions', $list);
    }

    public function test_drawer_starts_with_operational_tags(): void
    {
        $drawer = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-dictionary-drawer-content.blade.php',
        ));
        self::assertStringContainsString('KeywordTagResolver', $drawer);
        self::assertStringContainsString('operational_tags', $drawer);
        self::assertStringContainsString('technical_details', $drawer);
        self::assertStringNotContainsString('resolveTagLabelsForKeyword', $drawer);
        self::assertStringContainsString('displayTags', $drawer);
    }

    public function test_tag_column_uses_semantic_badges(): void
    {
        $cell = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php',
        ));
        self::assertStringContainsString('keyword-item__semantic', $cell);
        self::assertStringContainsString('KeywordItemPresenter', $cell);
    }

    private function keywordResourceSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'KeywordResource.php');
    }
}
