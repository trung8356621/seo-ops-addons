<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIntelligenceIngestionService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VocabularyKeywordIntelligenceIngestionServiceContractTest extends TestCase
{
    public function test_ingestion_service_uses_policy_and_article_vocabulary_provenance(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(VocabularyKeywordIntelligenceIngestionService::class))->getFileName(),
        );

        self::assertStringContainsString('ingestFromVocabularyGroups', $src);
        self::assertStringContainsString('VocabularyKeywordIngestionPolicy', $src);
        self::assertStringContainsString('article_vocabulary', $src);
        self::assertStringContainsString('Vocabulary Suggest', $src);
        self::assertStringNotContainsString('PromptResult::', $src);
        self::assertStringContainsString('evidenceMetaSuffix', $src);
    }

    public function test_policy_groups_remain_canonical_for_ingestion(): void
    {
        $policy = new VocabularyKeywordIngestionPolicy;
        self::assertSame('related_topics', $policy->resolveCanonicalGroup('Related topics'));
        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL));
    }
}