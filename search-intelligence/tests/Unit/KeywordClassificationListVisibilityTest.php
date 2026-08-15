<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordClassificationListVisibilityTest extends TestCase
{
    public function test_legacy_keyword_type_is_unchanged(): void
    {
        self::assertSame(['normal', 'suggest', 'free'], Keyword::allowedTypes());

        $resource = $this->keywordResourceSource();
        self::assertStringContainsString("return \$query->whereIn('type', \$types);", $resource);
        self::assertStringContainsString('legacy_type', $resource);
        self::assertStringContainsString('Keyword::allowedTypes()', $resource);
        self::assertStringNotContainsString("whereIn('type', KeywordClassificationVisibility", $resource);
    }

    public function test_list_eager_loads_classification_relation_and_advanced_filters(): void
    {
        $resource = $this->keywordResourceSource();
        self::assertStringContainsString('KeywordTagResolver::tableEagerLoad', $resource);
        self::assertStringContainsString("Filter::make('seo_classification')", $resource);
        self::assertStringContainsString('applyKindFilter', $resource);
        self::assertStringNotContainsString('seo-content-ai::filament.tables.columns.keyword-classification', $resource);
        self::assertStringNotContainsString("Filter::make('seo_usable')", $resource);
        self::assertStringNotContainsString("Filter::make('anchor_candidate')", $resource);
    }

    public function test_unclassified_is_explicit_not_blank(): void
    {
        self::assertSame('unclassified', KeywordClassificationVisibility::resolveKind(null));
        self::assertSame('unclassified', KeywordClassificationVisibility::UNCLASSIFIED);

        $cell = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/tables/columns/keyword-classification.blade.php',
        ));
        self::assertStringContainsString('KeywordClassificationVisibility::resolveKind', $cell);
        self::assertStringContainsString('classification_unclassified', $this->langSource('en'));
        self::assertStringContainsString('Chưa phân loại', $this->langSource('vi'));
    }

    public function test_keyword_model_defines_classification_relation(): void
    {
        self::assertTrue(method_exists(Keyword::class, 'seoClassification'));
    }

    public function test_kind_filter_includes_missing_rows_as_unclassified(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'KeywordIntelligence'.DIRECTORY_SEPARATOR.'KeywordClassificationVisibility.php');
        self::assertStringContainsString("whereDoesntHave('seoClassification')", $src);
        self::assertStringContainsString('applyKindFilter', $src);
        self::assertStringContainsString('applySeoUsableFilter', $src);
        self::assertStringContainsString('applyAnchorCandidateFilter', $src);
        self::assertStringContainsString('is_seo_keyword', $src);
        self::assertStringContainsString('is_anchor_candidate', $src);
    }

    public function test_list_page_exposes_summary_and_progress(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'KeywordResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListKeywords.php');
        self::assertStringContainsString('getClassificationSummary', $list);
        self::assertStringContainsString('getKeywordIntelligenceProgress', $list);
        self::assertStringContainsString('readProgress', $list);

        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/list-keywords.blade.php',
        ));
        self::assertStringContainsString('partials.keyword-classification-summary', $blade);
        self::assertStringContainsString("getKeywordWorkspaceMode() !== 'focus'", $blade);

        $summary = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-classification-summary.blade.php',
        ));
        self::assertStringContainsString("\$summary['focus']", $summary);
        self::assertStringContainsString("\$summary['error']", $summary);
        self::assertStringContainsString("\$summary['seo_excluded']", $summary);
        self::assertStringNotContainsString("\$summary['needs_review']", $summary);
        self::assertStringNotContainsString("\$summary['seo_usable']", $summary);
    }

    private function keywordResourceSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'KeywordResource.php');
    }

    private function langSource(string $locale): string
    {
        return (string) file_get_contents(LegacyAddonPath::resolve(
            'lang/'.$locale.'/filament.php',
        ));
    }
}
