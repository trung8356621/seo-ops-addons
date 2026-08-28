<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Tests\TestCase;

final class KeywordDictionaryFilterLabelTest extends TestCase
{
    public function test_source_filter_options_use_translated_labels_not_raw_codes(): void
    {
        $options = KeywordSourceNormalizer::filterOptions();

        self::assertSame(KeywordSourceNormalizer::all(), array_keys($options));
        self::assertSame('site_sync', KeywordSourceNormalizer::SITE_SYNC);
        self::assertNotSame(KeywordSourceNormalizer::SITE_SYNC, $options[KeywordSourceNormalizer::SITE_SYNC]);
        self::assertNotSame(KeywordSourceNormalizer::CONTENT_PROJECT, $options[KeywordSourceNormalizer::CONTENT_PROJECT]);
        self::assertNotSame(KeywordSourceNormalizer::AI_GENERATED, $options[KeywordSourceNormalizer::AI_GENERATED]);
    }

    public function test_intent_filter_options_use_translated_labels_not_raw_codes(): void
    {
        $options = KeywordRuleClassifier::intentFilterOptions();

        self::assertSame(KeywordRuleClassifier::intents(), array_keys($options));
        self::assertNotSame(KeywordRuleClassifier::INTENT_INFORMATIONAL, $options[KeywordRuleClassifier::INTENT_INFORMATIONAL]);
        self::assertSame(
            KeywordRuleClassifier::intentLabel(KeywordRuleClassifier::INTENT_COMMERCIAL),
            $options[KeywordRuleClassifier::INTENT_COMMERCIAL],
        );
    }

    public function test_classification_filter_options_remain_label_resolved(): void
    {
        $options = KeywordClassificationVisibility::filterOptions();

        self::assertArrayHasKey(KeywordRuleClassifier::KIND_KEYWORD_PHRASE, $options);
        self::assertNotSame(
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            $options[KeywordRuleClassifier::KIND_KEYWORD_PHRASE],
        );
        self::assertSame(
            KeywordClassificationVisibility::label(KeywordRuleClassifier::KIND_NOISE),
            $options[KeywordRuleClassifier::KIND_NOISE],
        );
    }
}
