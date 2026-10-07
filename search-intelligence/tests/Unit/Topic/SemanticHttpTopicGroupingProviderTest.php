<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\SemanticHttpTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInput;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingScope;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class SemanticHttpTopicGroupingProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 10,
            'semantic.topic_provider' => 'semantic_http',
        ]);
    }

    public function test_maps_request_and_preserves_refs_and_scores(): void
    {
        $hasher = new TopicGroupingInputHasher;
        $keywords = [
            ['ref' => '10', 'text' => 'balo học sinh'],
            ['ref' => '11', 'text' => 'balo sinh viên'],
            ['ref' => '12', 'text' => 'cách giặt áo thun'],
        ];
        $hash = $hasher->hash('7', 'vi', $keywords);

        Http::fake([
            'semantic.test/v1/topic/analyses' => Http::response([
                'analysis_id' => 'an-1',
                'status' => 'completed',
                'site_ref' => '7',
                'language' => 'vi',
                'input_hash' => $hash,
                'request_id' => 'req-1',
                'model' => [
                    'provider' => 'onnx_fastembed',
                    'name' => 'mini',
                    'version' => 'q',
                    'dimensions' => 384,
                ],
                'groups' => [[
                    'group_ref' => 'g-0001',
                    'suggested_label' => 'balo học sinh',
                    'member_count' => 2,
                    'mean_similarity' => 0.95,
                    'min_similarity' => 0.9,
                    'cohesion' => 0.94,
                    'members' => [
                        [
                            'keyword_ref' => '10',
                            'text' => 'balo học sinh',
                            'similarity_score' => 1.0,
                            'confidence' => 1.0,
                            'is_representative' => true,
                        ],
                        [
                            'keyword_ref' => '11',
                            'text' => 'balo sinh viên',
                            'similarity_score' => 0.9,
                            'confidence' => 0.8,
                            'is_representative' => false,
                        ],
                    ],
                ]],
                'unassigned' => [[
                    'keyword_ref' => '12',
                    'text' => 'cách giặt áo thun',
                    'reason' => 'below_threshold_or_small_component',
                ]],
                'diagnostics' => [
                    'keyword_count' => 3,
                    'group_count' => 1,
                    'unassigned_count' => 1,
                    'singleton_count' => 1,
                    'low_confidence_member_count' => 0,
                    'group_size_histogram' => ['2' => 1],
                    'timings_ms' => ['total_ms' => 12],
                    'embedding_cache' => ['hits' => 0, 'misses' => 3],
                    'algorithm' => 'cosine_threshold_greedy_medoid_v1',
                    'algorithm_config' => [],
                ],
                'started_at' => '2026-10-07T00:00:00+00:00',
                'finished_at' => '2026-10-07T00:00:01+00:00',
                'duration_ms' => 12,
                'error' => null,
            ], 200),
        ]);

        $proposal = $this->provider()->analyze($this->input());

        self::assertSame('an-1', $proposal->analysisRef);
        self::assertSame(SemanticHttpTopicGroupingProvider::KEY, $proposal->metadata[TopicGroupingProposal::META_PROVIDER]);
        self::assertCount(1, $proposal->groups);
        self::assertSame(10, $proposal->groups[0]->members[0]->keywordRef);
        self::assertSame(0.9, $proposal->groups[0]->members[1]->evidence['similarity_score']);
        self::assertSame(0.8, $proposal->groups[0]->members[1]->confidence);
        self::assertCount(1, $proposal->unassigned);
        self::assertSame(12, $proposal->unassigned[0]->keywordRef);
        self::assertSame($hash, $proposal->metadata['input_hash']);
    }

    public function test_rejects_unknown_keyword_ref(): void
    {
        $hasher = new TopicGroupingInputHasher;
        $hash = $hasher->hash('7', 'vi', [
            ['ref' => '10', 'text' => 'balo học sinh'],
            ['ref' => '11', 'text' => 'balo sinh viên'],
            ['ref' => '12', 'text' => 'cách giặt áo thun'],
        ]);

        Http::fake([
            'semantic.test/v1/topic/analyses' => Http::response([
                'analysis_id' => 'an-1',
                'status' => 'completed',
                'site_ref' => '7',
                'language' => 'vi',
                'input_hash' => $hash,
                'model' => ['provider' => 'x', 'name' => 'y', 'version' => 'z', 'dimensions' => 384],
                'groups' => [[
                    'group_ref' => 'g-1',
                    'suggested_label' => 'x',
                    'member_count' => 1,
                    'mean_similarity' => 1,
                    'min_similarity' => 1,
                    'cohesion' => 1,
                    'members' => [[
                        'keyword_ref' => '999',
                        'text' => 'ghost',
                        'similarity_score' => 1,
                        'confidence' => 1,
                        'is_representative' => true,
                    ]],
                ]],
                'unassigned' => [],
                'diagnostics' => ['algorithm' => 'x', 'low_confidence_member_count' => 0],
                'started_at' => 't',
                'finished_at' => 't',
                'duration_ms' => 1,
            ], 200),
        ]);

        $this->expectException(SemanticInvalidResponseException::class);
        $this->provider()->analyze($this->input());
    }

    public function test_rejects_input_hash_mismatch(): void
    {
        Http::fake([
            'semantic.test/v1/topic/analyses' => Http::response([
                'analysis_id' => 'an-1',
                'status' => 'completed',
                'site_ref' => '7',
                'language' => 'vi',
                'input_hash' => 'deadbeef',
                'model' => ['provider' => 'x', 'name' => 'y', 'version' => 'z', 'dimensions' => 384],
                'groups' => [],
                'unassigned' => [],
                'diagnostics' => ['algorithm' => 'x'],
                'started_at' => 't',
                'finished_at' => 't',
                'duration_ms' => 1,
            ], 200),
        ]);

        $this->expectException(SemanticInvalidResponseException::class);
        $this->provider()->analyze($this->input());
    }

    private function input(): TopicGroupingInput
    {
        return new TopicGroupingInput(
            siteRef: 7,
            language: 'vi',
            scope: TopicGroupingScope::SITE_RECLUSTER,
            candidates: [
                new TopicGroupingCandidate(10, 'balo học sinh'),
                new TopicGroupingCandidate(11, 'balo sinh viên'),
                new TopicGroupingCandidate(12, 'cách giặt áo thun'),
            ],
        );
    }

    private function provider(): SemanticHttpTopicGroupingProvider
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $matcher = new TopicMembershipMatcher($phrases);
        $legacy = new LegacyTopicGroupingProvider(
            new TopicClusterEngine($matcher, new KeywordNormalizer, $phrases),
            $matcher,
        );

        return new SemanticHttpTopicGroupingProvider(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            $legacy,
        );
    }
}
