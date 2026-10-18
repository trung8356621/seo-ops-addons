<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Jobs\PollKeywordGroupAnalysisJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisException;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisFinalizer;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetCanonical;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSnapshotBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Tests\TestCase;

final class KeywordGroupAnalysisFinalizeTest extends TestCase
{
    private const SITE = 8;

    /** @var list<array{ref: string, text: string, language: null, industry_evidence: array<int, mixed>, metadata: array<string, mixed>}> */
    private array $items = [];

    private string $remoteStatus = 'queued';

    private ?string $remoteError = null;

    private int $remotePages = 1;

    private ?string $rewriteRunId = null;

    private ?string $frozenHash = null;

    private int $httpCalls = 0;

    private bool $dropTransport = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
            'semantic.connect_timeout' => 1,
            'semantic.keyword_group_dataset_timeout' => 5,
            'cache.default' => 'array',
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $groups = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $groups->up();
        $runs = require dirname(__DIR__, 3).'/database/migrations/2026_10_17_120000_create_seo_keyword_group_analysis_runs.php';
        $runs->up();
        $this->items = $this->keywords(['1', '2']);
        $this->bindHttp();
        SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Old',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);
    }

    public function test_english_publish_does_not_replace_vietnamese_groups(): void
    {
        $siteId = 61;
        $vi = SeoKeywordGroup::query()->create([
            'site_id' => $siteId,
            'name' => 'Balo',
            'source' => KeywordGroupSource::SEMANTIC,
            'representative_keyword_id' => 10,
            'input_hash' => 'vi-hash',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => $siteId,
            'group_id' => $vi->id,
            'keyword_id' => 10,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => $siteId,
            'name' => 'Manual VI',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => true,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => $siteId,
            'group_id' => $manual->id,
            'keyword_id' => 11,
            'source' => KeywordGroupSource::MANUAL,
        ]);
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => $siteId,
            'run_id' => 'vi-run',
            'language' => 'vi',
            'dataset_revision' => 1,
            'dataset_hash' => 'vi-hash',
            'fingerprint' => 'fp-vi',
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'finalized',
        ]);

        $service = new KeywordGroupSemanticRefreshService(
            new SemanticAnalyticsClient(healthReporter: null),
            new TopicGroupingInputHasher(),
            new KeywordGroupCandidateLoader(),
        );
        $written = $service->publishAnalysisResponse($siteId, [
            'status' => 'completed',
            'input_hash' => 'en-hash',
            'diagnostics' => ['algorithm' => 'hybrid_semantic_lexical_v1'],
            'groups' => [[
                'group_ref' => 'en-1',
                'representative_ref' => '12',
                'representative_text' => 'school bag',
                'members' => [
                    ['ref' => '10', 'similarity_score' => 1],
                    ['ref' => '12', 'similarity_score' => 0.9],
                ],
            ]],
        ], [10 => true, 12 => true], 'en');

        self::assertSame(1, $written['groups']);
        self::assertSame(1, $written['members']);
        self::assertSame('Balo', SeoKeywordGroup::query()->whereKey($vi->id)->value('name'));
        self::assertSame('vi-hash', SeoKeywordGroup::query()->whereKey($vi->id)->value('input_hash'));
        self::assertSame(10, (int) SeoKeywordGroupKeyword::query()->where('group_id', $vi->id)->value('keyword_id'));
        self::assertSame(KeywordGroupSource::MANUAL, SeoKeywordGroup::query()->whereKey($manual->id)->value('source'));
        self::assertTrue((bool) SeoKeywordGroup::query()->whereKey($manual->id)->value('is_locked'));
        self::assertSame(11, (int) SeoKeywordGroupKeyword::query()->where('group_id', $manual->id)->value('keyword_id'));
        self::assertSame(12, (int) SeoKeywordGroupKeyword::query()->where('site_id', $siteId)->where('keyword_id', 12)->value('keyword_id'));
        self::assertNotSame((int) $vi->id, (int) SeoKeywordGroupKeyword::query()->where('keyword_id', 12)->value('group_id'));
    }

    public function test_completed_run_fails_when_members_belong_to_another_language(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_17_180000_scope_keyword_group_analysis_runs_by_language.php';
        $migration->up();

        $hash = KeywordGroupDatasetCanonical::datasetHash((string) self::SITE, 'en', $this->items);
        $this->frozenHash = $hash;
        $this->remoteStatus = 'completed';
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => self::SITE,
            'run_id' => 'legacy',
            'language' => null,
            'dataset_revision' => 1,
            'dataset_hash' => 'legacy-hash',
            'fingerprint' => 'fp-legacy',
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'finalized',
            'group_count' => 1,
            'member_count' => 2,
        ]);
        $legacy = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Legacy',
            'source' => KeywordGroupSource::SEMANTIC,
            'input_hash' => 'legacy-hash',
            'is_locked' => false,
        ]);
        foreach ([1, 2] as $keywordId) {
            SeoKeywordGroupKeyword::query()->create([
                'site_id' => self::SITE,
                'group_id' => $legacy->id,
                'keyword_id' => $keywordId,
                'source' => KeywordGroupSource::SEMANTIC,
            ]);
        }
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => self::SITE,
            'run_id' => 'run-1',
            'language' => 'en',
            'dataset_revision' => 3,
            'dataset_hash' => $hash,
            'fingerprint' => 'fp-1',
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'running',
        ]);

        $step = $this->finalizer()->advance(self::SITE, 'en');

        self::assertSame('failed', $step->phase);
        self::assertSame('grouped keywords already belong to another language', $step->error);
        self::assertSame(2, SeoKeywordGroupKeyword::query()->where('group_id', $legacy->id)->count());
        self::assertSame('finalized', SeoKeywordGroupAnalysisRun::query()->where('run_id', 'legacy')->value('phase'));
        self::assertSame(1, (int) SeoKeywordGroupAnalysisRun::query()->where('run_id', 'legacy')->value('group_count'));
    }

    public function test_queued_and_running_do_not_write_groups(): void
    {
        $this->remoteStatus = 'queued';
        $queued = $this->finalizer()->advance(self::SITE);
        self::assertSame('queued', $queued->phase);
        self::assertTrue($queued->pollAgain);
        $this->assertOldGroup();

        $this->remoteStatus = 'running';
        $running = $this->finalizer()->advance(self::SITE);
        self::assertSame('running', $running->phase);
        $this->assertOldGroup();
    }

    public function test_completed_pages_replace_semantic_groups_once(): void
    {
        $this->remoteStatus = 'completed';
        $done = $this->finalizer()->advance(self::SITE);
        self::assertSame('finalized', $done->phase);
        self::assertSame(1, $done->groupCount);
        self::assertSame(2, $done->memberCount);
        self::assertSame(1, SeoKeywordGroup::query()->where('site_id', self::SITE)->count());
        self::assertSame(2, SeoKeywordGroupKeyword::query()->where('site_id', self::SITE)->count());
        self::assertSame('keyword 1', SeoKeywordGroup::query()->where('site_id', self::SITE)->value('name'));

        $calls = $this->httpCalls;
        $again = $this->finalizer()->advance(self::SITE);
        self::assertSame('finalized', $again->phase);
        self::assertFalse($again->pollAgain);
        self::assertSame($calls, $this->httpCalls);
        self::assertSame(1, SeoKeywordGroup::query()->where('site_id', self::SITE)->count());
    }

    public function test_failed_incomplete_stale_and_superseded_runs_keep_mysql_groups(): void
    {
        $this->remoteStatus = 'failed';
        $this->remoteError = 'worker heartbeat expired';
        self::assertSame('failed', $this->finalizer()->advance(self::SITE)->phase);
        $this->assertOldGroup();

        SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->delete();
        $this->remoteStatus = 'completed';
        $this->remoteError = null;
        $this->remotePages = 2;
        self::assertSame('failed', $this->finalizer()->advance(self::SITE)->phase);
        $this->assertOldGroup();

        SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->delete();
        $this->items = $this->keywords(['1', '2']);
        $this->frozenHash = $this->hash();
        $this->remotePages = 1;
        $this->remoteStatus = 'queued';
        $started = $this->finalizer()->advance(self::SITE);
        $this->items = $this->keywords(['1']);
        $this->remoteStatus = 'completed';
        $stale = $this->finalizer()->advance(self::SITE);
        self::assertSame('stale', $stale->phase);
        self::assertSame($started->runId, $stale->runId);
        $this->assertOldGroup();

        SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->delete();
        $this->items = $this->keywords(['1', '2']);
        $this->frozenHash = $this->hash();
        $this->remoteStatus = 'completed';
        $this->rewriteRunId = 'run-new';
        self::assertSame('superseded', $this->finalizer()->advance(self::SITE)->phase);
        $this->assertOldGroup();
    }

    public function test_manual_membership_changes_the_snapshot_before_finalize(): void
    {
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $manual->id,
            'keyword_id' => 2,
            'source' => KeywordGroupSource::MANUAL,
        ]);
        $builder = new KeywordGroupDatasetSnapshotBuilder(new KeywordGroupCandidateLoader());
        $before = $builder->fromCandidates(self::SITE, null, [
            ['keyword_id' => 1, 'phrase' => 'keyword 1'],
            ['keyword_id' => 2, 'phrase' => 'keyword 2'],
        ]);
        self::assertSame(['1'], array_column($before, 'ref'));
        self::assertNotSame(
            KeywordGroupDatasetCanonical::datasetHash((string) self::SITE, null, $this->keywords(['1', '2'])),
            KeywordGroupDatasetCanonical::datasetHash((string) self::SITE, null, $before),
        );
    }

    public function test_finalize_keeps_groups_outside_the_snapshot(): void
    {
        $kept = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Vietnamese',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $kept->id,
            'keyword_id' => 99,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        $this->remoteStatus = 'completed';
        self::assertSame('finalized', $this->finalizer()->advance(self::SITE)->phase);
        self::assertNotNull(SeoKeywordGroup::query()->find($kept->id));
        self::assertSame(99, (int) SeoKeywordGroupKeyword::query()->where('group_id', $kept->id)->value('keyword_id'));
        self::assertSame(2, SeoKeywordGroup::query()->where('site_id', self::SITE)->count());
    }

    public function test_transaction_failure_rolls_back_and_network_loss_is_retryable(): void
    {
        $this->remoteStatus = 'completed';
        SeoKeywordGroup::creating(static function (): void {
            throw new \RuntimeException('forced rollback');
        });
        try {
            $this->finalizer()->advance(self::SITE);
            self::fail('publish must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('forced rollback', $e->getMessage());
        }
        SeoKeywordGroup::flushEventListeners();
        $this->assertOldGroup();
        self::assertNotSame('finalized', SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->value('phase'));

        SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->delete();
        $this->httpCalls = 0;
        $this->dropTransport = true;
        try {
            $this->finalizer()->advance(self::SITE);
            self::fail('transport must throw');
        } catch (KeywordGroupAnalysisException $e) {
            self::assertSame('transport', $e->reason);
        }
        self::assertSame(3, $this->httpCalls);
        self::assertNull(SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->value('run_id'));
        $this->assertOldGroup();
    }

    public function test_poll_job_releases_while_python_is_still_running(): void
    {
        $this->remoteStatus = 'queued';
        $this->finalizer()->advance(self::SITE);
        $this->remoteStatus = 'running';
        $job = (new PollKeywordGroupAnalysisJob(self::SITE))->withFakeQueueInteractions();
        \Illuminate\Support\Facades\Bus::fake();
        $job->handle($this->finalizer());
        $job->assertNotReleased();
        \Illuminate\Support\Facades\Bus::assertDispatched(PollKeywordGroupAnalysisJob::class, function (PollKeywordGroupAnalysisJob $next): bool {
            return $next->siteId === self::SITE && $next->delay !== null;
        });
        $this->assertOldGroup();

        $this->dropTransport = true;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            \Illuminate\Support\Facades\Bus::fake();
            (new PollKeywordGroupAnalysisJob(self::SITE))->handle($this->finalizer());
        }
        \Illuminate\Support\Facades\Bus::fake();
        (new PollKeywordGroupAnalysisJob(self::SITE))->handle($this->finalizer());
        self::assertSame('failed', SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->value('phase'));
        self::assertSame('semantic service unreachable', SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->value('last_error'));
        $this->assertOldGroup();
    }

    private function finalizer(): KeywordGroupAnalysisFinalizer
    {
        $builder = new class(new KeywordGroupCandidateLoader(), $this) extends KeywordGroupDatasetSnapshotBuilder
        {
            public function __construct(
                KeywordGroupCandidateLoader $loader,
                private KeywordGroupAnalysisFinalizeTest $test,
            ) {
                parent::__construct($loader);
            }

            public function build(int $siteId, ?string $language, ?array $languageVariants = null, array $evidenceByRef = []): array
            {
                return $this->test->items();
            }
        };
        $client = new SemanticAnalyticsClient(healthReporter: null);

        return new KeywordGroupAnalysisFinalizer(
            $client,
            new KeywordGroupDatasetSyncService($client, $builder),
            $builder,
            new KeywordGroupSemanticRefreshService($client, new TopicGroupingInputHasher(), new KeywordGroupCandidateLoader()),
        );
    }

    /**
     * @return list<array{ref: string, text: string, language: null, industry_evidence: array<int, mixed>, metadata: array<string, mixed>}>
     */
    public function items(): array
    {
        return $this->items;
    }

    private function bindHttp(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/datasets/')) {
                return $this->datasetResponse($this->frozenHash ?? $this->hash());
            }
            if ($this->dropTransport) {
                $this->httpCalls++;
                throw new ConnectionException('cURL error 28 timed out');
            }
            $this->httpCalls++;
            $hash = $this->frozenHash ?? $this->hash();
            if (str_contains($request->url(), '/groups')) {
                if ($this->rewriteRunId !== null) {
                    SeoKeywordGroupAnalysisRun::query()->where('site_id', self::SITE)->update(['run_id' => $this->rewriteRunId]);
                }
                if ($this->remotePages > 1 && ! str_contains($request->url(), 'cursor=1')) {
                    return Http::response($this->page([$this->group()], 1, $hash));
                }

                return Http::response($this->page([$this->group()], null, $hash));
            }

            return Http::response($this->runBody($this->remoteStatus, $this->remoteError, $hash));
        });
    }

    /**
     * @param  list<string>  $refs
     * @return list<array{ref: string, text: string, language: null, industry_evidence: array<int, mixed>, metadata: array<string, mixed>}>
     */
    private function keywords(array $refs): array
    {
        $items = [];
        foreach ($refs as $ref) {
            $items[] = [
                'ref' => $ref,
                'text' => 'keyword '.$ref,
                'language' => null,
                'industry_evidence' => [],
                'metadata' => [],
            ];
        }

        return $items;
    }

    private function hash(): string
    {
        return KeywordGroupDatasetCanonical::datasetHash((string) self::SITE, null, $this->items);
    }

    /**
     * @return array<string, mixed>
     */
    private function runBody(string $status, ?string $error, string $hash): array
    {
        return [
            'run_id' => 'run-1',
            'scope_ref' => (string) self::SITE,
            'dataset_revision' => 3,
            'dataset_hash' => $hash,
            'fingerprint' => 'fp-1',
            'status' => $status,
            'progress_phase' => $status,
            'progress_completed' => 0,
            'progress_total' => 2,
            'group_count' => 1,
            'member_count' => 2,
            'unassigned_count' => 0,
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'input_hash' => $hash,
            'error' => $error,
            'started_at' => null,
            'finished_at' => null,
            'heartbeat_at' => null,
            'created_at' => '2026-10-17T00:00:00+00:00',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function page(array $groups, ?int $cursor, string $hash): array
    {
        return [
            'run_id' => 'run-1',
            'status' => 'completed',
            'scope_ref' => (string) self::SITE,
            'input_hash' => $hash,
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'groups' => $groups,
            'next_cursor' => $cursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(): array
    {
        return [
            'group_ref' => 'g-0001',
            'representative_ref' => '1',
            'representative_text' => 'keyword 1',
            'member_count' => 2,
            'members' => [
                ['ref' => '1', 'text' => 'keyword 1', 'similarity_score' => 1, 'is_representative' => true],
                ['ref' => '2', 'text' => 'keyword 2', 'similarity_score' => 0.8, 'is_representative' => false],
            ],
        ];
    }

    private function datasetResponse(string $hash): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'scope_ref' => (string) self::SITE,
            'revision' => 3,
            'dataset_hash' => $hash,
            'language' => null,
            'keyword_count' => count($this->items),
            'committed_at' => '2026-10-17T00:00:00+00:00',
        ]);
    }

    private function assertOldGroup(): void
    {
        self::assertSame(1, SeoKeywordGroup::query()->where('site_id', self::SITE)->where('source', KeywordGroupSource::SEMANTIC)->count());
        self::assertSame('Old', SeoKeywordGroup::query()->where('site_id', self::SITE)->where('source', KeywordGroupSource::SEMANTIC)->value('name'));
        self::assertSame(0, SeoKeywordGroupKeyword::query()->where('site_id', self::SITE)->count());
    }
}
