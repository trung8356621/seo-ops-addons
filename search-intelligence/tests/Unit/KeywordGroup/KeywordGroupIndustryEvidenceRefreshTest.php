<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryContextKeyResolver;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroupSemanticDefinition;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupSemanticMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptMatchingClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Tests\TestCase;

final class KeywordGroupIndustryEvidenceRefreshTest extends TestCase
{
    private const SITE = 7;

    /** @var array<string, array<string, mixed>> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
            'semantic.connect_timeout' => 2,
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
    }

    public function test_excluded_keyword_never_reaches_concept_matching_or_grouping(): void
    {
        $this->fakeBoth([
            '1' => [
                'industry.products.balo.b1616081' => ['suggested' => true, 'lexical' => true, 'positive_max' => 0.48],
                'industry.audiences.school' => ['suggested' => false, 'lexical' => false, 'positive_max' => 0.91],
            ],
        ]);

        $result = $this->service($this->industryGroups())->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 1, 'phrase' => 'balo học sinh cấp 1'],
            ['keyword_id' => 3, 'phrase' => 'Zalo: 0909983833'],
            ['keyword_id' => 4, 'phrase' => 'https://example.com'],
        ]);

        self::assertSame('used', $result->industryEvidenceStatus);
        self::assertSame(1, $result->eligibleCount);
        self::assertSame(2, $result->excludedCount);
        self::assertSame(['contact_like' => 1, 'url_like' => 1], $result->excludedByReason);
        $conceptRefs = array_column($this->captured['concept']['entities'] ?? [], 'ref');
        $groupRefs = array_column($this->captured['group']['keywords'] ?? [], 'ref');
        self::assertSame(['1'], $conceptRefs);
        self::assertSame(['1'], $groupRefs);
        self::assertArrayNotHasKey('positive_max', $this->captured['group']['industry_evidence'][0]['memberships'][0]);
        self::assertSame('industry.products.balo.b1616081', $this->captured['group']['industry_evidence'][0]['memberships'][0]['key']);
        self::assertCount(1, $this->captured['group']['industry_evidence'][0]['memberships']);
        self::assertFalse(Schema::connection('omi_seo_ai')->hasTable('keyword_industry_groups'));
    }

    public function test_inactive_match_still_groups_without_industry_evidence(): void
    {
        $this->fakeBoth();
        $result = $this->service([], 'match_revision_inactive')->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 1, 'phrase' => 'balo học sinh'],
        ]);

        self::assertSame('match_revision_inactive', $result->industryEvidenceStatus);
        self::assertArrayNotHasKey('industry_evidence', $this->captured['group']);
        self::assertArrayNotHasKey('concept', $this->captured);
        self::assertFalse($result->skipped);
    }

    public function test_successful_match_with_no_suggestions_still_groups(): void
    {
        $this->fakeBoth([
            '1' => [
                'industry.products.balo.b1616081' => ['suggested' => false, 'lexical' => false, 'positive_max' => 0.88],
            ],
        ]);
        $result = $this->service($this->industryGroups())->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 1, 'phrase' => 'balo học sinh'],
        ]);

        self::assertSame('no_suggested_matches', $result->industryEvidenceStatus);
        self::assertSame(0, $result->industryMembershipCount);
        self::assertArrayNotHasKey('industry_evidence', $this->captured['group']);
        self::assertSame(['1'], array_column($this->captured['group']['keywords'], 'ref'));
    }

    public function test_concept_transport_failure_does_not_call_keyword_grouping(): void
    {
        Http::fake(fn () => Http::response('bad gateway', 502));
        $this->expectException(SemanticUnavailableException::class);
        try {
            $this->service($this->industryGroups())->refreshKeywords(self::SITE, 'vi', [
                ['keyword_id' => 1, 'phrase' => 'balo học sinh'],
            ]);
        } finally {
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/keyword-groups/analyses'));
        }
    }

    public function test_protected_keywords_are_omitted_from_industry_evidence(): void
    {
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual',
            'source' => KeywordGroupSource::MANUAL,
            'representative_keyword_id' => 10,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $manual->id,
            'keyword_id' => 10,
            'source' => KeywordGroupSource::MANUAL,
        ]);

        $this->fakeBoth([
            '1' => [
                'industry.products.balo.b1616081' => ['suggested' => true, 'lexical' => true, 'positive_max' => 0.5],
            ],
        ]);
        $this->service($this->industryGroups())->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 10, 'phrase' => 'manual keyword'],
            ['keyword_id' => 1, 'phrase' => 'balo học sinh'],
        ]);

        self::assertSame(['1'], array_column($this->captured['concept']['entities'], 'ref'));
        self::assertSame(['1'], array_column($this->captured['group']['keywords'], 'ref'));
        self::assertSame($manual->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', 10)->value('group_id'));
    }

    private function fakeBoth(array $overrides = []): void
    {
        $this->captured = [];
        Http::fake(function (Request $request) use ($overrides) {
            if (str_contains($request->url(), '/v1/concept-matches/')) {
                $this->captured['concept'] = $request->data();

                return Http::response($this->conceptResponse($request->data(), $overrides), 200);
            }
            if (str_contains($request->url(), '/v1/keyword-groups/')) {
                $this->captured['group'] = $request->data();

                return Http::response($this->groupingResponse($request->data()), 200);
            }

            return Http::response('missing', 404);
        });
    }

    /**
     * @param  list<IndustryGroup>  $groups
     */
    private function service(array $groups, string $status = 'active'): KeywordGroupSemanticRefreshService
    {
        $rules = new class($status) implements IndustryMatchRuleProvider
        {
            public function __construct(private readonly string $status) {}

            public function rulesForSite(int $siteId): array
            {
                return [];
            }

            public function rulesForKey(?string $industryContextKey): array
            {
                return [];
            }

            public function provenanceForKey(?string $industryContextKey): ?array
            {
                return null;
            }

            public function statusForKey(?string $industryContextKey): string
            {
                return $this->status;
            }
        };

        return new KeywordGroupSemanticRefreshService(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            new KeywordGroupCandidateLoader,
            industryKeys: new class implements IndustryContextKeyResolver
            {
                public function keyForSite(int $siteId): ?string
                {
                    return 'b2b-backpack';
                }
            },
            industryMatcher: new IndustryGroupSemanticMatcher(
                $this->provider($groups),
                new ConceptMatchingClient(new SemanticAnalyticsClient),
                $rules,
            ),
        );
    }

    /**
     * @param  list<IndustryGroup>  $groups
     */
    private function provider(array $groups): IndustryGroupProvider
    {
        return new class($groups) implements IndustryGroupProvider
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
                    static fn (IndustryGroup $group): IndustryGroupSemanticDefinition => $group->semanticDefinition(),
                    $this->groups,
                );
            }
        };
    }

    /** @return list<IndustryGroup> */
    private function industryGroups(): array
    {
        return [
            $this->group('industry.products.balo.b1616081', 'balo', IndustryGroupType::Products),
            $this->group('industry.audiences.school', 'trường học', IndustryGroupType::Audiences),
        ];
    }

    private function group(string $key, string $name, IndustryGroupType $type): IndustryGroup
    {
        return new IndustryGroup(
            key: $key,
            groupType: $type,
            sourceLocale: 'vi',
            name: $name,
            aliases: [],
            positiveExamples: [$name],
            negativeExamples: [],
            matchMode: 'phrase',
            provenance: [],
            stale: false,
            enabled: true,
            industryContextKey: 'b2b-backpack',
            requestedLocale: 'vi',
            effectiveLocale: 'vi',
            localized: false,
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, array<string, array<string, mixed>>>  $overrides
     * @return array<string, mixed>
     */
    private function conceptResponse(array $request, array $overrides): array
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
                    'matching_strategy' => 'hybrid',
                    'lexical' => [
                        'matched' => (bool) ($ov['lexical'] ?? false),
                        'match_mode' => 'phrase',
                        'matched_examples' => [],
                        'best_match' => null,
                        'negative_matched' => false,
                        'negative_matched_examples' => [],
                    ],
                    'positive_max' => $ov['positive_max'] ?? 0.2,
                    'positive_top_k_mean' => 0.2,
                    'negative_max' => null,
                    'margin' => null,
                    'best_positive_example' => $key,
                    'best_positive_similarity' => $ov['positive_max'] ?? 0.2,
                    'best_negative_example' => null,
                    'best_negative_similarity' => null,
                    'suggested_match' => $ov['suggested'] ?? false,
                ];
            }
            $entities[] = ['ref' => $ref, 'text' => (string) $entity['text'], 'concepts' => $concepts];
        }

        return [
            'analysis_id' => 'concept-1',
            'scope_ref' => (string) ($request['scope_ref'] ?? 'scope'),
            'language' => $request['language'] ?? 'vi',
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

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function groupingResponse(array $request): array
    {
        $keywords = (array) ($request['keywords'] ?? []);
        $first = $keywords[0] ?? ['ref' => '1', 'text' => 'balo'];
        $members = [];
        foreach ($keywords as $keyword) {
            $members[] = [
                'ref' => (string) $keyword['ref'],
                'text' => (string) $keyword['text'],
                'similarity_score' => 1,
                'is_representative' => (string) $keyword['ref'] === (string) $first['ref'],
            ];
        }

        return [
            'analysis_id' => 'group-1',
            'status' => 'completed',
            'scope_ref' => (string) ($request['scope_ref'] ?? '7'),
            'language' => $request['language'] ?? 'vi',
            'input_hash' => 'hash',
            'groups' => [[
                'group_ref' => 'g1',
                'representative_ref' => (string) $first['ref'],
                'representative_text' => (string) $first['text'],
                'member_count' => count($members),
                'mean_similarity' => 1,
                'min_similarity' => 1,
                'cohesion' => 1,
                'members' => $members,
            ]],
            'unassigned' => [],
            'diagnostics' => [
                'keyword_count' => count($members),
                'group_count' => 1,
                'unassigned_count' => 0,
                'algorithm' => 'hybrid_semantic_lexical_v1',
                'algorithm_config' => [],
                'timings_ms' => ['embed_ms' => 1, 'cluster_ms' => 1, 'total_ms' => 2],
                'embedding_cache' => ['hits' => 0, 'misses' => 1],
            ],
        ];
    }
}
