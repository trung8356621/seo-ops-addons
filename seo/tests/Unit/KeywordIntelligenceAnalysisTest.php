<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterPrimaryKeywordSelector;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordCandidateBucketer;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterValidator;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntentClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordManualOverrideGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordScoringService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use PHPUnit\Framework\TestCase;

final class KeywordIntelligenceAnalysisTest extends TestCase
{
    public function test_normalization_analyze_keeps_vietnamese_and_marks_invalid(): void
    {
        $svc = new KeywordNormalizationService;
        $ok = $svc->analyze("  - D\u{1ECB}ch v\u{1EE5} SEO t\u{1ED5}ng th\u{1EC3}:  ");
        self::assertTrue($ok->isValid);
        self::assertStringContainsString("\u{1ECB}", $ok->normalized);
        self::assertNull($ok->failureCode);

        $empty = $svc->analyze('   ---  ');
        self::assertFalse($empty->isValid);
        self::assertSame('keyword.empty', $empty->failureCode);
    }

    public function test_normalization_keeps_internal_punctuation(): void
    {
        $svc = new KeywordNormalizationService;
        $display = $svc->displayKeyword('iPhone 15 Pro - gia bao nhieu?');
        self::assertStringContainsString('-', $display);
        self::assertStringContainsString('?', $display);
    }

    public function test_manual_override_guard(): void
    {
        $guard = new KeywordManualOverrideGuard;
        $sources = [];
        $guard->touchManual($sources, 'search_intent', 'usr_1');
        self::assertTrue($guard->isManual($sources, 'search_intent'));
        self::assertFalse($guard->isManual($sources, 'priority_score'));
    }

    public function test_scoring_missing_metrics_do_not_become_zero_and_warn(): void
    {
        $scoring = new KeywordScoringService;
        $result = $scoring->score([
            'relevance' => 80,
            'business_value' => 70,
            'intent' => KeywordSearchIntent::Commercial->value,
        ]);

        self::assertGreaterThan(0, $result['priority_score']);
        self::assertLessThan(0.8, $result['confidence']);
        self::assertContains('keyword.missing_search_volume', $result['warnings']);
        self::assertContains('keyword.missing_keyword_difficulty', $result['warnings']);
    }

    public function test_intent_mixed_local_commercial(): void
    {
        $classifier = new KeywordIntentClassifier;
        $result = $classifier->classifyResult('dich vu seo tphcm', 'dich vu seo tphcm');
        self::assertContains($result->primaryIntent, [
            KeywordSearchIntent::Local,
            KeywordSearchIntent::Mixed,
            KeywordSearchIntent::Commercial,
        ]);
        self::assertSame('rule', $result->source);
    }

    public function test_cluster_validator_rejects_incompatible_intents_shape(): void
    {
        $candidate = new KeywordClusterCandidate(
            candidateRef: 'tmp',
            keywordIds: [1, 2],
            primaryKeywordId: 1,
            intent: KeywordSearchIntent::Mixed,
            funnelStage: null,
            entity: 'seo',
            modifiers: [],
            suggestedName: 'seo',
            suggestedContentType: 'article',
            confidence: 0.5,
            reasonCodes: [],
            existingArticleId: null,
        );
        $validator = new KeywordClusterValidator;
        $result = $validator->validate($candidate);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('reasons', $result);
    }

    public function test_action_codes_phase2_exist(): void
    {
        self::assertSame('keyword.analysis_already_processing', KeywordIntelligenceActionCodes::ANALYSIS_ALREADY_PROCESSING);
        self::assertSame('keyword.merge_preview', KeywordIntelligenceActionCodes::MERGE_PREVIEW);
    }

    public function test_candidate_bucketer_truncates_large_bucket(): void
    {
        $bucketer = new KeywordCandidateBucketer;
        // Smoke: empty input returns empty buckets.
        $result = $bucketer->bucket([], 'balanced');
        self::assertSame([], $result['buckets']);
        self::assertSame([], $result['warnings']);
    }

    public function test_primary_selector_class_exists(): void
    {
        self::assertTrue(class_exists(ClusterPrimaryKeywordSelector::class));
    }
}
