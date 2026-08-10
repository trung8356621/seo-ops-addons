<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCannibalizationIssue;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordCannibalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordCannibalizationTest extends TestCase
{
    public function test_issue_model_and_types_exist(): void
    {
        self::assertTrue(class_exists(SeoKeywordCannibalizationIssue::class));
        self::assertSame('c1_same_keyword_multi_article', KeywordCannibalizationIssueType::SameKeywordMultiArticle->value);
    }

    public function test_public_ref_kci(): void
    {
        $ref = KeywordIntelligencePublicRef::cannibalizationIssue(7);
        self::assertSame(7, KeywordIntelligencePublicRef::resolveCannibalizationIssueIdStrict($ref));
    }

    public function test_service_persists_by_fingerprint(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(KeywordCannibalizationService::class))->getFileName(),
        );
        self::assertStringContainsString('fingerprint', $source);
        self::assertStringContainsString('SeoKeywordCannibalizationIssue', $source);
        self::assertStringContainsString('Stale', $source);
    }
}
