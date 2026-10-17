<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetCanonical;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSnapshotBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSyncException;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Tests\TestCase;

final class KeywordGroupDatasetSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
            'semantic.keyword_group_dataset_timeout' => 5,
            'semantic.connect_timeout' => 1,
            'cache.default' => 'array',
            'semantic.keyword_group_dataset_retries' => 3,
            'semantic.keyword_group_dataset_batch_size' => 2,
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

    public function test_full_sync_then_noop_add_and_delete(): void
    {
        $remote = null;
        $this->sessions = 0;
        Http::fake(function (Request $request) use (&$remote) {
            return $this->remote($request, $remote);
        });

        $first = $this->service()->syncItems(6, 'vi', [
            $this->item('1', 'may phat'),
            $this->item('2', 'on ap'),
        ]);
        self::assertSame('committed', $first->status);
        self::assertSame(1, $first->revision);
        self::assertSame(2, $first->keywordCount);
        self::assertSame(1, $this->sessions);

        $second = $this->service()->syncItems(6, 'vi', [
            $this->item('2', 'on ap'),
            $this->item('1', 'may phat'),
        ]);
        self::assertSame('unchanged', $second->status);
        self::assertSame(1, $second->revision);
        self::assertSame(0, $second->batchCount);
        self::assertSame(1, $this->sessions);

        $third = $this->service()->syncItems(6, 'vi', [
            $this->item('1', 'may phat'),
            $this->item('2', 'on ap'),
            $this->item('3', 'tu dien'),
        ]);
        self::assertSame('committed', $third->status);
        self::assertSame(2, $third->revision);
        self::assertSame(['1', '2', '3'], $remote['refs']);

        $fourth = $this->service()->syncItems(6, 'vi', [
            $this->item('1', 'may phat'),
            $this->item('3', 'tu dien'),
        ]);
        self::assertSame('committed', $fourth->status);
        self::assertSame(['1', '3'], $remote['refs']);
        self::assertSame(0, $this->analysisCalls());
    }

    public function test_batch_retry_is_idempotent_and_conflict_or_mismatch_keep_revision(): void
    {
        $remote = null;
        $puts = 0;
        Http::fake(function (Request $request) use (&$remote, &$puts) {
            if ($request->method() === 'PUT') {
                $puts++;
                if ($puts === 1) {
                    throw new ConnectionException('cURL error 28 timed out');
                }
            }

            return $this->remote($request, $remote);
        });
        $result = $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat')]);
        self::assertSame('committed', $result->status);
        self::assertSame(2, $puts);
    }

    public function test_conflicting_batch_and_hash_mismatch_keep_the_committed_revision(): void
    {
        $remote = ['revision' => 4, 'dataset_hash' => 'kept', 'keyword_count' => 1, 'refs' => ['1']];
        $mode = 'conflict';
        Http::fake(function (Request $request) use (&$remote, &$mode) {
            if ($mode === 'conflict' && $request->method() === 'PUT') {
                return Http::response(['detail' => 'batch 1 already stored with a different payload'], 409);
            }
            if ($mode === 'mismatch' && str_contains($request->url(), '/commit')) {
                return Http::response(['detail' => 'dataset_hash mismatch'], 422);
            }

            return $this->remote($request, $remote);
        });
        try {
            $this->service()->syncItems(6, 'vi', [$this->item('1', 'khac')]);
            self::fail('conflict must throw');
        } catch (KeywordGroupDatasetSyncException $e) {
            self::assertSame('contract', $e->reason);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame(4, $remote['revision']);
        self::assertTrue($this->sawAbort());

        $mode = 'mismatch';
        try {
            $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat dien')]);
            self::fail('mismatch must throw');
        } catch (KeywordGroupDatasetSyncException $e) {
            self::assertSame('contract', $e->reason);
            self::assertSame(422, $e->httpStatus);
        }
        self::assertSame('kept', $remote['dataset_hash']);
    }

    public function test_stale_session_reconciles_same_hash_and_refuses_older_snapshot(): void
    {
        $remote = null;
        Http::fake(function (Request $request) use (&$remote) {
            if (str_contains($request->url(), '/commit')) {
                $remote = [
                    'revision' => 9,
                    'dataset_hash' => KeywordGroupDatasetCanonical::datasetHash('6', 'vi', [$this->item('1', 'may phat')]),
                    'keyword_count' => 1,
                    'refs' => ['1'],
                ];

                return Http::response(['detail' => 'stale session cannot overwrite a newer committed revision'], 409);
            }

            return $this->remote($request, $remote);
        });
        $same = $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat')]);
        self::assertSame('reconciled', $same->status);
        self::assertSame(9, $same->revision);
    }

    public function test_older_snapshot_does_not_replace_a_newer_revision(): void
    {
        $remote = [
            'revision' => 9,
            'dataset_hash' => 'other',
            'keyword_count' => 1,
            'refs' => ['9'],
        ];
        Http::fake(function (Request $request) use (&$remote) {
            if (str_contains($request->url(), '/commit')) {
                return Http::response(['detail' => 'stale session cannot overwrite a newer committed revision'], 409);
            }

            return $this->remote($request, $remote);
        });
        try {
            $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat')]);
            self::fail('older snapshot must not replace the newer revision');
        } catch (KeywordGroupDatasetSyncException $e) {
            self::assertSame('stale_session', $e->reason);
        }
        self::assertSame('other', $remote['dataset_hash']);
    }

    public function test_overlapping_sync_does_not_start_a_second_upload(): void
    {
        $lock = Cache::lock('keyword-group-dataset-sync:6:vi', 60);
        self::assertTrue($lock->get());
        Http::fake();
        try {
            $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat')]);
            self::fail('overlap must throw');
        } catch (KeywordGroupDatasetSyncException $e) {
            self::assertSame('in_progress', $e->reason);
        }
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_missing_remote_dataset_is_rebuilt_and_empty_snapshot_makes_no_request(): void
    {
        $remote = null;
        Http::fake(function (Request $request) use (&$remote) {
            return $this->remote($request, $remote);
        });
        $rebuilt = $this->service()->syncItems(6, 'vi', [$this->item('1', 'may phat')]);
        self::assertSame('committed', $rebuilt->status);

        Http::fake();
        $empty = $this->service()->syncItems(6, 'vi', []);
        self::assertSame('empty', $empty->status);
        Http::assertNothingSent();
    }

    public function test_snapshot_skips_ineligible_and_protected_keywords_and_keeps_evidence(): void
    {
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'Manual',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 6,
            'group_id' => $manual->id,
            'keyword_id' => 2,
            'source' => KeywordGroupSource::MANUAL,
        ]);

        $items = (new KeywordGroupDatasetSnapshotBuilder(new \Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader()))
            ->fromCandidates(6, 'vi', [
                ['keyword_id' => 1, 'phrase' => 'may phat'],
                ['keyword_id' => 2, 'phrase' => 'manual phrase'],
                ['keyword_id' => 3, 'phrase' => 'sales@example.com'],
            ], [
                '1' => [['key' => 'b', 'group_type' => 'product'], ['key' => 'a', 'group_type' => 'brand']],
            ]);

        self::assertSame(['1'], array_column($items, 'ref'));
        self::assertSame('a', $items[0]['industry_evidence'][1]['key']);
        self::assertSame(
            KeywordGroupDatasetCanonical::datasetHash('6', 'vi', [[
                'ref' => '1',
                'text' => 'may phat',
                'language' => 'vi',
                'industry_evidence' => [
                    ['key' => 'b', 'group_type' => 'product'],
                    ['key' => 'a', 'group_type' => 'brand'],
                ],
                'metadata' => [],
            ]]),
            KeywordGroupDatasetCanonical::datasetHash('6', 'vi', $items),
        );
    }

    private function service(): KeywordGroupDatasetSyncService
    {
        return new KeywordGroupDatasetSyncService(
            new SemanticAnalyticsClient(healthReporter: null),
            new KeywordGroupDatasetSnapshotBuilder(new \Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader()),
        );
    }

    /** @var list<string> */
    private array $pendingRefs = [];

    private int $sessions = 0;

    /**
     * @param  array<string, mixed>|null  $remote
     */
    private function remote(Request $request, ?array &$remote): mixed
    {
        self::assertStringNotContainsString('/analyses', $request->url());
        self::assertStringNotContainsString('/embedding-batches', $request->url());
        if ($request->method() === 'GET') {
            if ($remote === null) {
                return Http::response(['detail' => 'dataset_not_found'], 404);
            }

            return Http::response([
                'scope_ref' => '6',
                'revision' => $remote['revision'],
                'dataset_hash' => $remote['dataset_hash'],
                'language' => 'vi',
                'keyword_count' => $remote['keyword_count'],
                'committed_at' => '2026-10-17T00:00:00+00:00',
            ]);
        }
        if (str_contains($request->url(), '/sessions') && $request->method() === 'POST' && ! str_contains($request->url(), '/commit') && ! str_contains($request->url(), '/abort')) {
            $this->sessions++;
            $this->pendingRefs = [];

            return Http::response([
                'session_id' => 'session-1',
                'scope_ref' => '6',
                'revision' => ($remote['revision'] ?? 0) + 1,
                'expected_keyword_count' => $request->data()['expected_keyword_count'],
                'expected_batch_count' => $request->data()['expected_batch_count'],
                'expires_at' => '2026-10-18T00:00:00+00:00',
            ], 201);
        }
        if ($request->method() === 'PUT') {
            foreach ($request->data()['keywords'] ?? [] as $keyword) {
                $this->pendingRefs[] = (string) $keyword['ref'];
            }
            sort($this->pendingRefs);

            return Http::response([
                'session_id' => 'session-1',
                'batch_index' => 1,
                'keyword_count' => count($request->data()['keywords'] ?? []),
                'batch_hash' => 'batch',
                'idempotent' => false,
            ]);
        }
        if (str_contains($request->url(), '/abort')) {
            return Http::response('', 204);
        }
        if (str_contains($request->url(), '/commit')) {
            $remote = [
                'revision' => ($remote['revision'] ?? 0) + 1,
                'dataset_hash' => (string) $request->data()['dataset_hash'],
                'keyword_count' => count($this->pendingRefs),
                'refs' => $this->pendingRefs,
            ];

            return Http::response([
                'scope_ref' => '6',
                'session_id' => 'session-1',
                'revision' => $remote['revision'],
                'dataset_hash' => $remote['dataset_hash'],
                'keyword_count' => $remote['keyword_count'],
                'status' => 'committed',
                'unchanged' => false,
            ]);
        }

        return Http::response(['detail' => 'unexpected'], 500);
    }

    /**
     * @return array{ref: string, text: string, language: ?string, industry_evidence: list<array{key: string, group_type: string}>, metadata: array<string, mixed>}
     */
    private function item(string $ref, string $text): array
    {
        return [
            'ref' => $ref,
            'text' => $text,
            'language' => 'vi',
            'industry_evidence' => [],
            'metadata' => [],
        ];
    }

    private function sawAbort(): bool
    {
        $found = false;
        Http::recorded(function (Request $request) use (&$found): void {
            if (str_contains($request->url(), '/abort')) {
                $found = true;
            }
        });

        return $found;
    }

    private function analysisCalls(): int
    {
        $count = 0;
        Http::recorded(function (Request $request) use (&$count): void {
            if (str_contains($request->url(), '/analyses') || str_contains($request->url(), '/embedding-batches')) {
                $count++;
            }
        });

        return $count;
    }
}
