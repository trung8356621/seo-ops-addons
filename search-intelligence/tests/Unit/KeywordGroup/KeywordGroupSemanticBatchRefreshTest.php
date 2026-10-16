<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Tests\TestCase;

final class KeywordGroupSemanticBatchRefreshTest extends TestCase
{
    private const SITE = 6;

    /** @var list<array{url: string, data: array<string, mixed>}> */
    private array $requests = [];

    private int $activeCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
            'semantic.connect_timeout' => 2,
            'semantic.keyword_group_batch_size' => 1000,
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

    public function test_batch_sizes_and_cross_batch_group_persistence(): void
    {
        $this->armSuccessfulHttp();
        foreach ([100, 1999, 2000, 3720, 10000] as $count) {
            $this->resetGroups();
            $this->requests = [];
            $this->activeCount = $count;

            $result = $this->service()->refreshKeywords(self::SITE, 'vi', $this->keywords($count));

            $embed = array_values(array_filter(
                $this->requests,
                static fn (array $row): bool => str_contains($row['url'], '/embedding-batches'),
            ));
            $analyses = array_values(array_filter(
                $this->requests,
                static fn (array $row): bool => str_ends_with($row['url'], '/v1/keyword-groups/analyses'),
            ));
            $cluster = array_values(array_filter(
                $this->requests,
                static fn (array $row): bool => str_contains($row['url'], '/analyses/from-cache'),
            ));

            if ($count <= 1000) {
                self::assertSame([], $embed, 'count '.$count);
                self::assertCount(1, $analyses, 'count '.$count);
                self::assertSame([], $cluster, 'count '.$count);
                self::assertCount($count, $analyses[0]['data']['keywords']);
            } else {
                self::assertSame([], $analyses, 'count '.$count);
                self::assertCount(1, $cluster, 'count '.$count);
                $expectedChunks = (int) ceil($count / 1000);
                self::assertCount($expectedChunks, $embed, 'count '.$count);
                $seen = 0;
                foreach ($embed as $index => $row) {
                    $size = count($row['data']['keywords']);
                    self::assertLessThanOrEqual(1000, $size, 'count '.$count);
                    self::assertGreaterThan(0, $size);
                    $seen += $size;
                    if ($index < $expectedChunks - 1) {
                        self::assertSame(1000, $size, 'count '.$count);
                    }
                }
                self::assertSame($count, $seen, 'count '.$count);
                $clusterRefs = array_column($cluster[0]['data']['keywords'], 'ref');
                self::assertCount($count, $clusterRefs);
                self::assertSame($clusterRefs, array_values(array_unique($clusterRefs)));
            }

            self::assertSame(1, $result->groupCount, 'count '.$count);
            $groupIds = SeoKeywordGroupKeyword::query()->whereIn('keyword_id', [1, $count])->pluck('group_id')->all();
            self::assertCount(2, $groupIds, 'count '.$count);
            self::assertSame($groupIds[0], $groupIds[1], 'count '.$count);
            self::assertSame(2, SeoKeywordGroupKeyword::query()->count(), 'count '.$count);
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/topic/analyses'));
        }
    }

    public function test_midway_embedding_failure_does_not_replace_groups(): void
    {
        $existing = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Keep',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => 11,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $existing->id,
            'keyword_id' => 11,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        $embedCalls = 0;
        Http::fake(function (Request $request) use (&$embedCalls) {
            if (str_contains($request->url(), '/embedding-batches')) {
                $embedCalls++;
                if ($embedCalls === 2) {
                    return Http::response(['detail' => 'batch exploded'], 500);
                }

                return Http::response(['keyword_count' => 1000, 'embedding_cache' => ['hits' => 0, 'misses' => 1000]], 200);
            }

            return Http::response(['detail' => 'cluster should not run'], 500);
        });

        try {
            $this->service()->refreshKeywords(self::SITE, 'vi', $this->keywords(2500));
            self::fail('expected batch failure');
        } catch (SemanticTransportException $e) {
            self::assertStringContainsString('embedding batch 2/3 failed', $e->getMessage());
            self::assertStringContainsString('500', $e->getMessage());
        }

        self::assertSame(2, $embedCalls);
        self::assertSame($existing->id, SeoKeywordGroup::query()->value('id'));
        self::assertSame(11, (int) SeoKeywordGroupKeyword::query()->value('keyword_id'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/analyses/from-cache'));
    }

    public function test_retry_after_failure_replaces_once_without_duplicate_memberships(): void
    {
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual',
            'source' => KeywordGroupSource::MANUAL,
            'representative_keyword_id' => 900001,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $manual->id,
            'keyword_id' => 900001,
            'source' => KeywordGroupSource::MANUAL,
        ]);
        $locked = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Locked',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => 900002,
            'is_locked' => true,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $locked->id,
            'keyword_id' => 900002,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        $attempt = 0;
        Http::fake(function (Request $request) use (&$attempt) {
            $refs = array_column($request->data()['keywords'] ?? [], 'ref');
            self::assertNotContains('900001', $refs);
            self::assertNotContains('900002', $refs);
            if (str_contains($request->url(), '/embedding-batches')) {
                return Http::response(['keyword_count' => count($refs)], 200);
            }
            $attempt++;
            if ($attempt === 1) {
                return Http::response(['detail' => 'cluster down'], 503);
            }

            return Http::response($this->globalGroupResponse(1500), 200);
        });

        $keywords = array_merge($this->keywords(1500), [
            ['keyword_id' => 900001, 'phrase' => 'manual keyword'],
            ['keyword_id' => 900002, 'phrase' => 'locked keyword'],
        ]);

        try {
            $this->service()->refreshKeywords(self::SITE, 'vi', $keywords);
            self::fail('expected cluster failure');
        } catch (SemanticTransportException $e) {
            self::assertStringContainsString('keyword group clustering failed', $e->getMessage());
        }

        self::assertNull(SeoKeywordGroup::query()->where('source', KeywordGroupSource::SEMANTIC)->where('is_locked', false)->value('id'));
        self::assertSame($manual->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', 900001)->value('group_id'));
        self::assertSame($locked->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', 900002)->value('group_id'));

        $this->service()->refreshKeywords(self::SITE, 'vi', $keywords);
        $this->service()->refreshKeywords(self::SITE, 'vi', $keywords);

        self::assertSame(2, SeoKeywordGroupKeyword::query()->whereNotIn('keyword_id', [900001, 900002])->count());
        self::assertSame(
            1,
            SeoKeywordGroup::query()->where('source', KeywordGroupSource::SEMANTIC)->where('is_locked', false)->count(),
        );
        self::assertSame($manual->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', 900001)->value('group_id'));
        self::assertSame('Manual', SeoKeywordGroup::query()->find($manual->id)?->name);
        self::assertTrue((bool) SeoKeywordGroup::query()->find($locked->id)?->is_locked);
        $pair = SeoKeywordGroupKeyword::query()->whereIn('keyword_id', [1, 1500])->pluck('group_id')->unique()->values()->all();
        self::assertCount(1, $pair);
    }

    private function armSuccessfulHttp(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();
            $this->requests[] = ['url' => $request->url(), 'data' => $data];
            if (str_contains($request->url(), '/embedding-batches')) {
                return Http::response([
                    'keyword_count' => count($data['keywords'] ?? []),
                    'embedding_cache' => ['hits' => 0, 'misses' => count($data['keywords'] ?? [])],
                ], 200);
            }

            return Http::response($this->globalGroupResponse($this->activeCount), 200);
        });
    }

    private function service(): KeywordGroupSemanticRefreshService
    {
        return new KeywordGroupSemanticRefreshService(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            new KeywordGroupCandidateLoader,
        );
    }

    /**
     * @return list<array{keyword_id: int, phrase: string}>
     */
    private function keywords(int $count): array
    {
        $rows = [];
        for ($id = 1; $id <= $count; $id++) {
            $rows[] = ['keyword_id' => $id, 'phrase' => 'balo học sinh '.$id];
        }

        return $rows;
    }

    private function resetGroups(): void
    {
        SeoKeywordGroupKeyword::query()->delete();
        SeoKeywordGroup::query()->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function globalGroupResponse(int $count): array
    {
        return [
            'analysis_id' => 'group-batch',
            'status' => 'completed',
            'scope_ref' => (string) self::SITE,
            'language' => 'vi',
            'input_hash' => 'hash',
            'groups' => [[
                'group_ref' => 'g-cross',
                'representative_ref' => '1',
                'representative_text' => 'balo học sinh 1',
                'member_count' => 2,
                'mean_similarity' => 0.9,
                'min_similarity' => 0.8,
                'cohesion' => 0.8,
                'members' => [
                    ['ref' => '1', 'text' => 'balo học sinh 1', 'similarity_score' => 1, 'is_representative' => true],
                    ['ref' => (string) $count, 'text' => 'balo học sinh '.$count, 'similarity_score' => 0.84, 'is_representative' => false],
                ],
            ]],
            'unassigned' => [],
            'diagnostics' => [
                'keyword_count' => $count,
                'group_count' => 1,
                'unassigned_count' => $count - 2,
                'algorithm' => 'hybrid_semantic_lexical_v1',
                'algorithm_config' => [],
                'timings_ms' => ['embed_ms' => 1, 'cluster_ms' => 1, 'total_ms' => 2],
                'embedding_cache' => ['hits' => $count, 'misses' => 0],
            ],
        ];
    }
}
