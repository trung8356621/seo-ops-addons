<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\IndustryGroup;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroupSemanticDefinition;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupMatchEntity;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupSemanticMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptMatchingClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticDisabledException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Tests\TestCase;

final class IndustryGroupSemanticMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 30,
            'semantic.connect_timeout' => 2,
            'semantic.concept_match_entity_batch_size' => 2,
        ]);
    }

    public function test_definition_exposes_match_mode_and_hybrid_payload_without_threshold(): void
    {
        $group = $this->group('industry.products.balo.test', 'balo', matchMode: 'token');
        $def = $group->semanticDefinition();
        self::assertSame('token', $def->matchMode);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response($this->pythonOkResponse($captured), 200);
        });

        $matcher = $this->matcher([$group]);
        $matcher->match(
            'scope:1',
            [new IndustryGroupMatchEntity('e1', 'balo học sinh')],
        );

        self::assertNotNull($captured);
        self::assertSame('hybrid', $captured['concepts'][0]['matching_strategy']);
        self::assertSame('token', $captured['concepts'][0]['match_mode']);
        self::assertFalse($captured['decision_policy']['semantic_fallback']);
        self::assertArrayNotHasKey('min_positive_score', $captured['decision_policy']);
        $matcherSource = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/IndustryGroup/IndustryGroupSemanticMatcher.php',
        );
        self::assertStringNotContainsString(
            'use Omnichannel\\Addons\\SearchFoundation\\Services\\MatchRules\\MatchRuleMatcher',
            $matcherSource,
        );
    }

    public function test_balo_and_zalo_lexical_suggested_behavior(): void
    {
        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response($this->pythonOkResponse($payload, [
                'e1' => ['industry.products.balo.test' => [
                    'lexical' => true,
                    'suggested' => true,
                    'positive_max' => 0.48,
                ]],
                'e2' => ['industry.products.balo.test' => [
                    'lexical' => false,
                    'suggested' => false,
                    'positive_max' => 0.49,
                ]],
            ]), 200);
        });

        $result = $this->matcher([$this->group('industry.products.balo.test', 'balo', matchMode: 'token')])
            ->match('scope:1', [
                new IndustryGroupMatchEntity('e1', 'balo học sinh'),
                new IndustryGroupMatchEntity('e2', 'Zalo 0909983833'),
            ]);

        self::assertTrue($result->calledPython);
        $e1 = $result->entities[0]->evidence[0];
        $e2 = $result->entities[1]->evidence[0];
        self::assertTrue($e1->lexicalMatched);
        self::assertTrue($e1->suggestedMatch);
        self::assertSame(0.48, $e1->positiveMax);
        self::assertFalse($e2->lexicalMatched);
        self::assertFalse($e2->suggestedMatch);
        self::assertSame(0.49, $e2->positiveMax);
    }

    public function test_stale_and_disabled_skipped(): void
    {
        Http::fake(function ($request) {
            return Http::response($this->pythonOkResponse($request->data()), 200);
        });

        $active = $this->group('industry.products.balo.test', 'balo');
        $stale = $this->group('industry.products.stale.test', 'stale', stale: true);
        $disabled = $this->group('industry.products.off.test', 'off', enabled: false);

        $result = $this->matcher([$active, $stale, $disabled])->match(
            'scope:1',
            [new IndustryGroupMatchEntity('e1', 'balo học sinh')],
        );

        self::assertSame(1, $result->conceptsUsed);
        self::assertSame(1, $result->staleGroupsSkipped);
        self::assertSame(1, $result->disabledGroupsSkipped);
        self::assertTrue($result->calledPython);
        Http::assertSentCount(1);
    }

    public function test_no_industry_groups_skips_api(): void
    {
        Http::fake();
        $result = $this->matcher([])->match(
            'scope:1',
            [new IndustryGroupMatchEntity('e1', 'balo')],
        );
        self::assertFalse($result->calledPython);
        self::assertSame('no_industry_groups', $result->reason);
        Http::assertNothingSent();
    }

    public function test_semantic_disabled_throws_without_http(): void
    {
        config(['semantic.enabled' => false]);
        Http::fake();
        $this->expectException(SemanticDisabledException::class);
        $this->matcher([$this->group('industry.products.balo.test', 'balo')])->match(
            'scope:1',
            [new IndustryGroupMatchEntity('e1', 'balo')],
        );
        Http::assertNothingSent();
    }

    public function test_entity_batching_merges_deterministically(): void
    {
        config(['semantic.concept_match_entity_batch_size' => 2]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response($this->pythonOkResponse($request->data()), 200);
        });

        $result = $this->matcher([$this->group('industry.products.balo.test', 'balo')])->match(
            'scope:1',
            [
                new IndustryGroupMatchEntity('a', 't1'),
                new IndustryGroupMatchEntity('b', 't2'),
                new IndustryGroupMatchEntity('c', 't3'),
            ],
        );

        self::assertSame(2, $calls);
        self::assertSame(['a', 'b', 'c'], array_map(
            static fn ($e) => $e->ref,
            $result->entities,
        ));
    }

    public function test_duplicate_refs_rejected_before_http(): void
    {
        Http::fake();
        $this->expectException(\InvalidArgumentException::class);
        $this->matcher([$this->group('industry.products.balo.test', 'balo')])->match(
            'scope:1',
            [
                new IndustryGroupMatchEntity('dup', 'a'),
                new IndustryGroupMatchEntity('dup', 'b'),
            ],
        );
        Http::assertNothingSent();
    }

    public function test_malformed_python_response_is_invalid_not_empty(): void
    {
        Http::fake(fn () => Http::response(['scope_ref' => 'x', 'entities' => []], 200));
        $this->expectException(SemanticInvalidResponseException::class);
        $this->matcher([$this->group('industry.products.balo.test', 'balo')])->match(
            'scope:1',
            [new IndustryGroupMatchEntity('e1', 'balo')],
        );
    }

    public function test_python_unavailable_propagates_without_laravel_fallback(): void
    {
        Http::fake(fn () => Http::response('bad gateway', 502));
        $this->expectException(SemanticUnavailableException::class);
        try {
            $this->matcher([$this->group('industry.products.balo.test', 'balo')])->match(
                'scope:1',
                [new IndustryGroupMatchEntity('e1', 'balo')],
            );
        } catch (SemanticUnavailableException $e) {
            $matcherSource = (string) file_get_contents(
                dirname(__DIR__, 3).'/src/Services/IndustryGroup/IndustryGroupSemanticMatcher.php',
            );
            self::assertStringNotContainsString('similar_text', $matcherSource);
            self::assertStringNotContainsString('levenshtein', $matcherSource);
            self::assertStringNotContainsString('use Omnichannel\\Addons\\SearchFoundation\\Services\\MatchRules\\MatchRuleMatcher', $matcherSource);
            throw $e;
        }
    }

    public function test_client_uses_semantic_analytics_client_not_keyword_models(): void
    {
        $matcherSource = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/IndustryGroup/IndustryGroupSemanticMatcher.php',
        );
        $clientSource = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Semantic/ConceptMatching/ConceptMatchingClient.php',
        );
        self::assertStringContainsString('SemanticAnalyticsClient', $clientSource);
        self::assertStringNotContainsString('Http::', $clientSource);
        self::assertStringNotContainsString('Keyword::', $matcherSource);
        self::assertStringNotContainsString('seo_keywords', $matcherSource);
        self::assertStringNotContainsString('DB::', $matcherSource);
    }

    /**
     * @param  list<IndustryGroup>  $groups
     */
    private function matcher(array $groups): IndustryGroupSemanticMatcher
    {
        $provider = new class($groups) implements IndustryGroupProvider
        {
            /** @param list<IndustryGroup> $groups */
            public function __construct(private array $groups) {}

            public function list(?int $siteId = null, ?string $industryContextKey = null, ?string $locale = null): array
            {
                return $this->groups;
            }

            public function find(string $key, ?int $siteId = null, ?string $industryContextKey = null, ?string $locale = null): ?IndustryGroup
            {
                foreach ($this->groups as $group) {
                    if ($group->key === $key) {
                        return $group;
                    }
                }

                return null;
            }

            public function semanticDefinitions(?int $siteId = null, ?string $industryContextKey = null, ?string $locale = null): array
            {
                return array_map(
                    static fn (IndustryGroup $g): IndustryGroupSemanticDefinition => $g->semanticDefinition(),
                    $this->groups,
                );
            }
        };

        return new IndustryGroupSemanticMatcher(
            $provider,
            new ConceptMatchingClient(new SemanticAnalyticsClient),
        );
    }

    private function group(
        string $key,
        string $name,
        string $matchMode = 'phrase',
        bool $stale = false,
        bool $enabled = true,
    ): IndustryGroup {
        return new IndustryGroup(
            key: $key,
            groupType: IndustryGroupType::Products,
            sourceLocale: 'vi',
            name: $name,
            aliases: [$name === 'balo' ? 'ba lô' : $name],
            positiveExamples: [],
            negativeExamples: [],
            matchMode: $matchMode,
            provenance: ['group' => 'products'],
            stale: $stale,
            enabled: $enabled,
            industryContextKey: 'bags',
            requestedLocale: 'vi',
            effectiveLocale: 'vi',
            localized: true,
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, array<string, array<string, mixed>>>  $overrides
     * @return array<string, mixed>
     */
    private function pythonOkResponse(array $request, array $overrides = []): array
    {
        $entities = [];
        foreach ((array) ($request['entities'] ?? []) as $entity) {
            $ref = (string) $entity['ref'];
            $concepts = [];
            foreach ((array) ($request['concepts'] ?? []) as $concept) {
                $key = (string) $concept['key'];
                $ov = $overrides[$ref][$key] ?? [];
                $concepts[] = [
                    'key' => $key,
                    'matching_strategy' => (string) ($concept['matching_strategy'] ?? 'hybrid'),
                    'lexical' => [
                        'matched' => (bool) ($ov['lexical'] ?? false),
                        'match_mode' => (string) ($concept['match_mode'] ?? 'phrase'),
                        'matched_examples' => ($ov['lexical'] ?? false) ? ['balo'] : [],
                        'best_match' => ($ov['lexical'] ?? false) ? 'balo' : null,
                        'negative_matched' => false,
                        'negative_matched_examples' => [],
                    ],
                    'positive_max' => $ov['positive_max'] ?? 0.4,
                    'positive_top_k_mean' => 0.35,
                    'negative_max' => null,
                    'margin' => null,
                    'best_positive_example' => 'balo',
                    'best_positive_similarity' => $ov['positive_max'] ?? 0.4,
                    'best_negative_example' => null,
                    'best_negative_similarity' => null,
                    'suggested_match' => $ov['suggested'] ?? false,
                ];
            }
            $entities[] = [
                'ref' => $ref,
                'text' => (string) $entity['text'],
                'concepts' => $concepts,
            ];
        }

        return [
            'analysis_id' => 'test-analysis',
            'scope_ref' => (string) ($request['scope_ref'] ?? 'scope'),
            'language' => $request['language'] ?? null,
            'entities' => $entities,
            'diagnostics' => [
                'entity_count' => count($entities),
                'concept_count' => count((array) ($request['concepts'] ?? [])),
                'unique_text_count' => count($entities),
                'embed_ms' => 1,
                'score_ms' => 1,
                'total_ms' => 2,
                'model' => 'test',
                'provider' => 'test',
                'dimensions' => 3,
                'cache' => 'direct_embed_batch',
            ],
        ];
    }
}
