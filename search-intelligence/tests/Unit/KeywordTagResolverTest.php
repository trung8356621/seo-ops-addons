<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;
use PHPUnit\Framework\TestCase;

final class KeywordTagResolverTest extends TestCase
{
    private KeywordTagResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KeywordTagResolver();
    }

    public function test_seo_usable_becomes_focus(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'is_ambiguous' => false,
            'confidence' => 0.80,
        ]);

        self::assertSame([KeywordTag::FOCUS], $tags);
    }

    public function test_review_band_confidence_still_focus_when_seo_usable(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'is_ambiguous' => false,
            'confidence' => 0.68,
        ]);

        self::assertSame([KeywordTag::FOCUS], $tags);
    }

    public function test_query_and_brand_still_focus_when_seo_usable(): void
    {
        foreach ([KeywordRuleClassifier::KIND_QUERY, KeywordRuleClassifier::KIND_BRAND_ENTITY] as $kind) {
            $tags = $this->resolver->resolve([
                'classified' => true,
                'phrase_kind' => $kind,
                'is_seo_keyword' => true,
                'is_ambiguous' => false,
                'confidence' => 0.94,
            ]);
            self::assertSame([KeywordTag::FOCUS], $tags, $kind);
        }
    }

    public function test_ambiguous_seo_usable_stays_focus(): void
    {
        $ambiguous = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'is_ambiguous' => true,
            'confidence' => 0.96,
        ]);

        self::assertSame([KeywordTag::FOCUS], $ambiguous);
    }

    public function test_unclassified_is_focus_not_review(): void
    {
        self::assertSame([KeywordTag::FOCUS], $this->resolver->resolve([
            'classified' => false,
            'is_seo_keyword' => null,
        ]));
    }

    public function test_manual_error_is_secondary_on_focus(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'manual_error' => true,
        ]);

        self::assertSame([KeywordTag::FOCUS, KeywordTag::ERROR], $tags);
    }

    public function test_manual_error_hidden_when_seo_excluded(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_SENTENCE,
            'is_seo_keyword' => false,
            'manual_error' => true,
        ]);

        self::assertSame([KeywordTag::SEO_EXCLUDED], $tags);
        self::assertNotContains(KeywordTag::ERROR, $tags);
    }

    public function test_custom_groups_append_after_workflow(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'internal_link_count' => 1,
            'workflow' => KeywordTag::PUBLISHED,
            'groups' => [KeywordTag::groupCode(12)],
        ]);

        self::assertSame([
            KeywordTag::FOCUS,
            KeywordTag::HAS_LINK,
            KeywordTag::PUBLISHED,
            KeywordTag::groupCode(12),
        ], $tags);
    }

    public function test_excluded_kinds_and_non_seo_become_seo_excluded(): void
    {
        $sentence = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_SENTENCE,
            'is_seo_keyword' => false,
            'confidence' => 0.99,
        ]);
        $flag = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => false,
            'confidence' => 0.99,
        ]);

        self::assertSame([KeywordTag::SEO_EXCLUDED], $sentence);
        self::assertSame([KeywordTag::SEO_EXCLUDED], $flag);
    }

    public function test_seo_true_with_internal_link_and_published(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'confidence' => 0.80,
            'internal_link_count' => 1,
            'workflow' => KeywordTag::PUBLISHED,
        ]);

        self::assertSame([KeywordTag::FOCUS, KeywordTag::HAS_LINK, KeywordTag::PUBLISHED], $tags);
    }

    public function test_linked_article_without_internal_link_does_not_add_has_link(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'internal_link_count' => 0,
            'linked_articles_count' => 1,
        ]);

        self::assertSame([KeywordTag::FOCUS], $tags);
        self::assertNotContains(KeywordTag::HAS_LINK, $tags);
    }

    public function test_primary_tag_order_is_seo_then_link_then_workflow(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'confidence' => 0.96,
            'internal_link_count' => 2,
            'workflow' => KeywordTag::PUBLISHED,
        ]);

        self::assertSame([KeywordTag::FOCUS, KeywordTag::HAS_LINK, KeywordTag::PUBLISHED], $tags);
    }

    public function test_workflow_tags(): void
    {
        $map = [
            KeywordTag::WRITING => 'writing',
            KeywordTag::PENDING_REVIEW => 'pending_review',
            KeywordTag::PENDING_PUBLISH => 'pending_publish',
            KeywordTag::PUBLISHED => 'published',
        ];
        foreach ($map as $tag => $workflow) {
            $tags = $this->resolver->resolve([
                'classified' => true,
                'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
                'is_seo_keyword' => true,
                'confidence' => 0.96,
                'workflow' => $workflow,
            ]);
            self::assertSame([KeywordTag::FOCUS, $tag], $tags, $workflow);
        }
    }

    public function test_excluded_plus_published_does_not_become_focus(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_SENTENCE,
            'is_seo_keyword' => false,
            'internal_link_count' => 0,
            'workflow' => KeywordTag::PUBLISHED,
        ]);

        self::assertSame([KeywordTag::SEO_EXCLUDED, KeywordTag::PUBLISHED], $tags);
        self::assertNotContains(KeywordTag::FOCUS, $tags);
    }

    public function test_zero_links_does_not_add_has_link(): void
    {
        $tags = $this->resolver->resolve([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'confidence' => 0.96,
            'internal_link_count' => 0,
        ]);

        self::assertSame([KeywordTag::FOCUS], $tags);
    }

    public function test_generation_allows_focus_not_excluded(): void
    {
        self::assertTrue($this->resolver->allowsAiGeneration([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'confidence' => 0.96,
        ]));
        self::assertTrue($this->resolver->allowsAiGeneration([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'confidence' => 0.70,
        ]));
        self::assertFalse($this->resolver->allowsAiGeneration([
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_SENTENCE,
            'is_seo_keyword' => false,
        ]));
    }

    public function test_summary_primary_matches_row_primary(): void
    {
        $state = [
            'classified' => true,
            'phrase_kind' => KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            'is_seo_keyword' => true,
            'is_ambiguous' => false,
            'confidence' => 0.80,
            'internal_link_count' => 3,
            'workflow' => KeywordTag::PUBLISHED,
        ];
        $tags = $this->resolver->resolve($state);
        self::assertSame($this->resolver->primarySeoTag($state), $tags[0]);
        self::assertSame(KeywordTag::FOCUS, $tags[0]);
    }
}
