<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VocabularySuggestDictionaryBoundaryTest extends TestCase
{
    public function test_dictionary_excludes_suggest_type_from_query_and_filters(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordResource::class))->getFileName(),
        );
        self::assertStringContainsString('excludeStagingSuggestTypes', $resource);
        self::assertStringContainsString("orWhere('type', '!=', Keyword::TYPE_SUGGEST)", $resource);

        $filterMethod = $this->methodSource($resource, 'keywordTypeFilterOptions');
        self::assertStringNotContainsString('TYPE_SUGGEST', $filterMethod);
        self::assertStringContainsString('TYPE_NORMAL', $filterMethod);
        self::assertStringContainsString('TYPE_FREE', $filterMethod);

        $selectMethod = $this->methodSource($resource, 'keywordTypeSelectOptions');
        self::assertStringNotContainsString('TYPE_SUGGEST', $selectMethod);
    }

    public function test_staging_query_targets_suggest_ai_generated(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(VocabularySuggestStagingQuery::class))->getFileName(),
        );
        self::assertStringContainsString('TYPE_SUGGEST', $src);
        self::assertStringContainsString('AI_GENERATED', $src);
        self::assertStringContainsString('forSite', $src);
        self::assertSame('ai_generated', KeywordSourceNormalizer::AI_GENERATED);
        self::assertSame('suggest', Keyword::TYPE_SUGGEST);
    }

    public function test_classification_summary_excludes_staging_suggest(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery::class
            ))->getFileName(),
        );
        self::assertStringContainsString('excludeStagingSuggestTypes', $src);
        self::assertStringContainsString('applyMinimumKeywordWordCount', $src);
        self::assertStringContainsString("whereNotNull('source_article_id')", $src);
    }

    public function test_list_keywords_requires_link_scope_for_active_dictionary(): void
    {
        $listSrc = (string) file_get_contents(
            dirname((string) (new ReflectionClass(KeywordResource::class))->getFileName())
            .DIRECTORY_SEPARATOR.'KeywordResource'
            .DIRECTORY_SEPARATOR.'Pages'
            .DIRECTORY_SEPARATOR.'ListKeywords.php',
        );
        self::assertStringContainsString('KeywordDictionaryQuery', $listSrc);
        self::assertStringContainsString('currentDictionaryFilterBag', $listSrc);
    }

    private function methodSource(string $file, string $method): string
    {
        if (! preg_match('/function '.$method.'\([^{]*\{([\s\S]*?)\n    \}/', $file, $m)) {
            self::fail("method {$method} not found");
        }

        return $m[1];
    }
}
