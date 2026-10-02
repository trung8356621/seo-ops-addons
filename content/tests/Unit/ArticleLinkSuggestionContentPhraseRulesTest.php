<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentPhraseExtractor;
use Omnichannel\Addons\SearchFoundation\Contracts\GlobalMatchRuleProvider;
use Omnichannel\Addons\Seo\Services\MatchRules\GlobalMatchRuleRegistry;
use ReflectionMethod;
use Tests\TestCase;

final class ArticleLinkSuggestionContentPhraseRulesTest extends TestCase
{
    public function test_container_injects_global_match_rules_into_extractor(): void
    {
        $extractor = app(ArticleLinkSuggestionContentPhraseExtractor::class);
        $property = new \ReflectionProperty($extractor, 'globalRules');

        self::assertInstanceOf(GlobalMatchRuleProvider::class, $property->getValue($extractor));
    }

    public function test_registry_exposes_all_internal_link_phrase_rule_groups(): void
    {
        $definitions = (new GlobalMatchRuleRegistry)->definitions();

        self::assertArrayHasKey('link_heading_prefixes', $definitions);
        self::assertArrayHasKey('link_phrase_connectors', $definitions);
        self::assertArrayHasKey('link_ngram_leading_stopwords', $definitions);
        self::assertArrayHasKey('link_phrase_stopwords', $definitions);
    }

    public function test_default_heading_prefix_is_stripped(): void
    {
        $extractor = $this->extractor();

        self::assertSame(
            'chọn vật liệu',
            $extractor->stripHeadingPrefix('Hướng dẫn chọn vật liệu'),
        );
    }

    public function test_heading_prefix_behavior_changes_with_configured_rule(): void
    {
        $extractor = $this->extractor(['link_heading_prefixes' => ['overview']]);

        self::assertSame('Hướng dẫn chọn vật liệu', $extractor->stripHeadingPrefix('Hướng dẫn chọn vật liệu'));
        self::assertSame('materials', $extractor->stripHeadingPrefix('Overview materials'));
    }

    public function test_missing_provider_and_explicit_empty_heading_rule_are_fail_neutral(): void
    {
        self::assertSame(
            'Hướng dẫn chọn vật liệu',
            (new ArticleLinkSuggestionContentPhraseExtractor)->stripHeadingPrefix('Hướng dẫn chọn vật liệu'),
        );
        self::assertSame(
            'Hướng dẫn chọn vật liệu',
            $this->extractor(['link_heading_prefixes' => []])->stripHeadingPrefix('Hướng dẫn chọn vật liệu'),
        );
    }

    public function test_connector_rule_controls_phrase_acceptance(): void
    {
        self::assertFalse($this->invokeBool(
            $this->extractor(['link_phrase_connectors' => ['bridge']]),
            'isAcceptablePhrase',
            ['bridge entity'],
        ));
        self::assertTrue($this->invokeBool(
            $this->extractor(['link_phrase_connectors' => []]),
            'isAcceptablePhrase',
            ['bridge entity'],
        ));
    }

    public function test_ngram_leading_stopword_rule_controls_repeated_candidate(): void
    {
        self::assertSame([], $this->invokeArray(
            $this->extractor(['link_ngram_leading_stopwords' => ['alpha']]),
            'repeatedNgrams',
            ['alpha beta alpha beta', 2, 2, 2],
        ));
        self::assertSame(['alpha beta'], $this->invokeArray(
            $this->extractor(['link_ngram_leading_stopwords' => []]),
            'repeatedNgrams',
            ['alpha beta alpha beta', 2, 2, 2],
        ));
    }

    public function test_phrase_stopword_rule_controls_stopword_only_rejection(): void
    {
        self::assertTrue($this->invokeBool(
            $this->extractor(['link_phrase_stopwords' => ['alpha', 'beta']]),
            'isStopwordOnlyTokens',
            [['alpha', 'beta']],
        ));
        self::assertFalse($this->invokeBool(
            $this->extractor(['link_phrase_stopwords' => []]),
            'isStopwordOnlyTokens',
            [['alpha', 'beta']],
        ));
    }

    /** @param array<string, list<string>> $overrides */
    private function extractor(array $overrides = []): ArticleLinkSuggestionContentPhraseExtractor
    {
        $defaults = array_map(
            static fn (array $definition): array => $definition['defaults'],
            (new GlobalMatchRuleRegistry)->definitions(),
        );
        $rules = array_replace($defaults, $overrides);
        $provider = new class($rules) implements GlobalMatchRuleProvider
        {
            /** @param array<string, list<string>> $rules */
            public function __construct(private readonly array $rules) {}

            public function globalMatchRules(): array
            {
                return $this->rules;
            }
        };

        return new ArticleLinkSuggestionContentPhraseExtractor($provider);
    }

    /** @param list<mixed> $arguments */
    private function invokeBool(object $target, string $method, array $arguments): bool
    {
        return (bool) (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }

    /** @param list<mixed> $arguments
     * @return list<string>
     */
    private function invokeArray(object $target, string $method, array $arguments): array
    {
        return (array) (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}
