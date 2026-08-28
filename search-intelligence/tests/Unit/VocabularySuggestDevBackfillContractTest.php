<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Console\VocabularySuggestDevBackfillCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularySuggestDevBackfillService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VocabularySuggestDevBackfillContractTest extends TestCase
{
    public function test_backfill_uses_strong_groups_only_and_canonical_ingest(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(VocabularySuggestDevBackfillService::class))->getFileName(),
        );
        self::assertStringContainsString('ingestFromVocabularyGroups', $src);
        self::assertStringContainsString('VocabularyKeywordIntelligenceIngestionService', $src);
        self::assertStringContainsString('seo_article_keywords', $src);
        self::assertStringNotContainsString('PromptResult', $src);
        self::assertStringNotContainsString('prompt_results', $src);
        self::assertContains(VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS, VocabularySuggestDevBackfillService::BACKFILL_GROUPS);
        self::assertContains(VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL, VocabularySuggestDevBackfillService::BACKFILL_GROUPS);
        self::assertNotContains(VocabularyKeywordIngestionPolicy::GROUP_HOLONYMY, VocabularySuggestDevBackfillService::BACKFILL_GROUPS);
        self::assertNotContains(VocabularyKeywordIngestionPolicy::GROUP_SALIENT_KEYWORDS, VocabularySuggestDevBackfillService::BACKFILL_GROUPS);
    }

    public function test_command_signature(): void
    {
        $props = (new ReflectionClass(VocabularySuggestDevBackfillCommand::class))->getDefaultProperties();
        self::assertStringContainsString('seo:vocabulary:backfill-suggest', (string) ($props['signature'] ?? ''));
        self::assertStringContainsString('dry-run', (string) ($props['signature'] ?? ''));
    }
}
