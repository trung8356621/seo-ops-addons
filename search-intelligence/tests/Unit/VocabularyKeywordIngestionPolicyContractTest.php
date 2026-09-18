<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Runtime contract for Vocabulary group policy (SEO Audit Idea Candidate labels / ingest filters).
 * Must execute resolveCanonicalGroup — not string-scan source.
 */
final class VocabularyKeywordIngestionPolicyContractTest extends TestCase
{
    public function test_policy_class_exists_and_enables_canonical_groups(): void
    {
        self::assertTrue(class_exists(VocabularyKeywordIngestionPolicy::class));

        $policy = new VocabularyKeywordIngestionPolicy;

        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS));
        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL));
        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC));
        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_HOLONYMY));
        self::assertFalse($policy->isEnabled('synonyms'));
        self::assertFalse($policy->isEnabled('unknown_group'));
    }

    public function test_resolve_canonical_group_maps_enabled_headings(): void
    {
        $policy = new VocabularyKeywordIngestionPolicy;

        self::assertSame(
            VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS,
            $policy->resolveCanonicalGroup('### Related topics'),
        );
        self::assertSame(
            VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL,
            $policy->resolveCanonicalGroup('Long-tail keywords'),
        );
        self::assertSame(
            VocabularyKeywordIngestionPolicy::GROUP_HOLONYMY,
            $policy->resolveCanonicalGroup('**Holonymy**'),
        );
    }

    public function test_resolve_canonical_group_excludes_noise_headings(): void
    {
        $policy = new VocabularyKeywordIngestionPolicy;

        self::assertNull($policy->resolveCanonicalGroup('Synonyms'));
        self::assertNull($policy->resolveCanonicalGroup('Antonyms'));
        self::assertNull($policy->resolveCanonicalGroup('N-grams'));
        self::assertNull($policy->resolveCanonicalGroup('LSI'));
        self::assertNull($policy->resolveCanonicalGroup('LSI Keywords'));
    }

    public function test_evidence_meta_suffix_contract(): void
    {
        $policy = new VocabularyKeywordIngestionPolicy;

        self::assertSame(
            'vocab_evidence.'.VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS,
            $policy->evidenceMetaSuffix(VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS),
        );
    }
}
