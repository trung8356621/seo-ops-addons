<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscUrlInspectionRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscUrlInspectionRunItem;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionApiException;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionBindingResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionClient;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionHealthMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionLockService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionResult;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionService;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexCheck;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Tests\TestCase;

/**
 * Phase 8B integration — fake Google transport → canonical Index Health.
 */
final class GscUrlInspectionIndexHealthIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    /** @var callable|null */
    private $transport;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_index_checks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_article_index_health')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_url_inspection_runs')
        ) {
            $this->fail('Index Health / GSC inspection tables missing — run local migration first (no SKIP).');
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_article_index_checks', 'diagnostics')) {
            $this->fail('seo_article_index_checks.diagnostics missing — run Phase 8B migration.');
        }
    }

    public function test_pass_records_indexed_with_gsc_source(): void
    {
        $article = $this->createPublishedArticle();
        $this->transport = fn (): array => $this->googlePayload('PASS');

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);

        self::assertTrue($result['ok']);
        self::assertSame('indexed', $result['check_status']);
        self::assertSame('gsc_url_inspection', $result['source']);
        $check = SeoArticleIndexCheck::query()->where('article_id', $article->id)->latest('id')->first();
        self::assertSame('indexed', $check?->status);
        self::assertSame('gsc_url_inspection', $check?->source);
        self::assertSame('PASS', $check?->diagnostics['verdict'] ?? null);
    }

    public function test_fail_first_time_is_not_dropped(): void
    {
        $article = $this->createPublishedArticle();
        $this->transport = fn (): array => $this->googlePayload('FAIL');

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);

        self::assertTrue($result['ok']);
        self::assertSame('not_indexed', $result['effective_health']);
        self::assertFalse($result['transitioned_to_dropped']);
    }

    public function test_drop_and_recovery_through_recorder(): void
    {
        $article = $this->createPublishedArticle();
        app(ArticleIndexHealthRecorder::class)->record(
            $article,
            ArticleIndexCheckStatus::Indexed,
            'manual',
            1,
            Carbon::parse('2026-07-01'),
        );

        $this->transport = fn (): array => $this->googlePayload('FAIL');
        $drop = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertSame('dropped', $drop['effective_health']);
        self::assertTrue($drop['transitioned_to_dropped']);

        $this->transport = fn (): array => $this->googlePayload('PASS');
        $recover = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertSame('indexed', $recover['effective_health']);
        self::assertTrue($recover['recovered_from_dropped']);
    }

    public function test_partial_maps_unknown(): void
    {
        $article = $this->createPublishedArticle();
        $this->transport = fn (): array => $this->googlePayload('PARTIAL');

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertSame('unknown', $result['check_status']);
        self::assertSame('unknown', $result['effective_health']);
    }

    public function test_http_failure_does_not_mutate_health(): void
    {
        $article = $this->createPublishedArticle();
        app(ArticleIndexHealthRecorder::class)->record($article, ArticleIndexCheckStatus::Indexed, 'manual', 1);
        $before = SeoArticleIndexHealth::query()->where('article_id', $article->id)->first();
        $checksBefore = SeoArticleIndexCheck::query()->where('article_id', $article->id)->count();

        $this->transport = static function (): array {
            throw GscUrlInspectionApiException::rateLimited('quota');
        };

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertFalse($result['ok']);
        self::assertSame($checksBefore, SeoArticleIndexCheck::query()->where('article_id', $article->id)->count());
        $after = SeoArticleIndexHealth::query()->where('article_id', $article->id)->first();
        self::assertSame($before?->current_status, $after?->current_status);
        self::assertEquals($before?->last_checked_at?->toIso8601String(), $after?->last_checked_at?->toIso8601String());
    }

    public function test_unpublished_rejected_without_api(): void
    {
        $article = $this->createPublishedArticle('draft');
        $called = false;
        $this->transport = static function () use (&$called): array {
            $called = true;

            return [];
        };

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertFalse($result['ok']);
        self::assertSame('article.not_eligible', $result['error_code']);
        self::assertFalse($called);
    }

    public function test_uses_observed_permalink_not_slug(): void
    {
        $article = $this->createPublishedArticle();
        $permalink = (string) $article->wordpressLink?->observed_permalink;
        $seenUrl = null;
        $this->transport = function (string $token, string $url, string $property) use (&$seenUrl): array {
            $seenUrl = $url;

            return $this->googlePayload('PASS');
        };

        $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertSame($permalink, $seenUrl);
        self::assertSame($permalink, SeoArticleIndexCheck::query()->where('article_id', $article->id)->value('url'));
    }

    public function test_canonical_mismatch_keeps_indexed(): void
    {
        $article = $this->createPublishedArticle();
        $this->transport = fn (): array => $this->googlePayload(
            'PASS',
            googleCanonical: 'https://example.test/other/',
            userCanonical: 'https://example.test/same/',
        );

        $result = $this->makeService()->inspectArticle((int) $article->id, 1);
        self::assertSame('indexed', $result['effective_health']);
        $check = SeoArticleIndexCheck::query()->where('article_id', $article->id)->latest('id')->first();
        self::assertTrue((bool) ($check?->diagnostics['canonical_mismatch'] ?? false));
    }

    public function test_history_mix_manual_and_gsc(): void
    {
        $article = $this->createPublishedArticle();
        $recorder = app(ArticleIndexHealthRecorder::class);
        $recorder->record($article, ArticleIndexCheckStatus::Indexed, 'manual', 1, Carbon::parse('2026-07-01'));
        $this->transport = fn (): array => $this->googlePayload('PASS');
        $this->makeService()->inspectArticle((int) $article->id, 1);
        $recorder->record($article, ArticleIndexCheckStatus::Unknown, 'manual', 1, Carbon::parse('2026-08-20'));

        $sources = SeoArticleIndexCheck::query()
            ->where('article_id', $article->id)
            ->orderBy('id')
            ->pluck('source')
            ->all();
        self::assertSame(['manual', 'gsc_url_inspection', 'manual'], $sources);
    }

    public function test_batch_partial_success(): void
    {
        $siteId = 9_500_100;
        $ok1 = $this->createPublishedArticle('publish', $siteId);
        $ok2 = $this->createPublishedArticle('publish', $siteId);
        $fail = $this->createPublishedArticle('publish', $siteId);

        $calls = 0;
        $this->transport = function () use (&$calls, $fail): array {
            $calls++;
            if ($calls === 3) {
                throw GscUrlInspectionApiException::transient('network');
            }

            return $this->googlePayload('PASS');
        };

        $binding = $this->fakeBinding($siteId);
        $service = $this->makeService($binding);
        $runs = new GscUrlInspectionRunService(
            $service,
            $binding,
            dispatchAsync: false,
        );

        // Inject binding by creating run items manually after queue with stubbed resolver via processSynchronously
        // Use reflection-free path: create run + process with our service by temporarily binding in container
        $this->app->instance(GscUrlInspectionService::class, $service);
        $this->app->instance(GscUrlInspectionBindingResolver::class, $binding);

        $syncRuns = new GscUrlInspectionRunService(
            inspection: $service,
            bindings: $binding,
            dispatchAsync: false,
        );

        // queueForArticles needs binding resolve — fake resolver returns property
        $queued = $syncRuns->queueForArticles($siteId, [(int) $ok1->id, (int) $ok2->id, (int) $fail->id], 1, 10);
        self::assertTrue($queued['ok'] ?? false);
        $summary = $syncRuns->processRun((int) $queued['run_id']);

        self::assertSame(2, (int) $summary['inspected']);
        self::assertSame(1, (int) $summary['failed']);
        self::assertSame('partial', $summary['status']);
        self::assertSame(2, SeoArticleIndexCheck::query()->whereIn('article_id', [$ok1->id, $ok2->id])->where('source', 'gsc_url_inspection')->count());
        self::assertSame(0, SeoArticleIndexCheck::query()->where('article_id', $fail->id)->count());
    }

    public function test_rate_limit_skips_remaining_without_health_mutation(): void
    {
        $siteId = 9_500_200;
        $a = $this->createPublishedArticle('publish', $siteId);
        $b = $this->createPublishedArticle('publish', $siteId);
        $c = $this->createPublishedArticle('publish', $siteId);

        $calls = 0;
        $this->transport = function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return $this->googlePayload('PASS');
            }
            throw GscUrlInspectionApiException::rateLimited('quota');
        };

        $binding = $this->fakeBinding($siteId);
        $service = $this->makeService($binding);
        $runs = new GscUrlInspectionRunService($service, $binding, dispatchAsync: false);
        $queued = $runs->queueForArticles($siteId, [(int) $a->id, (int) $b->id, (int) $c->id], 1, 10);
        $summary = $runs->processRun((int) $queued['run_id']);

        self::assertSame(1, (int) $summary['inspected']);
        self::assertGreaterThanOrEqual(1, (int) $summary['failed']);
        $skipped = SeoGscUrlInspectionRunItem::query()
            ->where('run_id', $queued['run_id'])
            ->where('status', 'skipped')
            ->count();
        self::assertGreaterThanOrEqual(1, $skipped);
        self::assertSame(1, SeoArticleIndexCheck::query()->where('site_id', $siteId)->where('source', 'gsc_url_inspection')->count());
    }

    public function test_property_missing_no_api(): void
    {
        $article = $this->createPublishedArticle();
        $called = false;
        $this->transport = static function () use (&$called): array {
            $called = true;

            return [];
        };

        $binding = new class extends GscUrlInspectionBindingResolver
        {
            public function resolveForSite(int $siteId): array
            {
                throw GscUrlInspectionApiException::missingBinding('GSC property is not bound for this site.');
            }
        };

        $result = $this->makeService($binding)->inspectArticle((int) $article->id, 1);
        self::assertFalse($result['ok']);
        self::assertSame('gsc.property_missing', $result['error_code']);
        self::assertFalse($called);
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePayload(
        string $verdict,
        ?string $googleCanonical = null,
        ?string $userCanonical = null,
    ): array {
        return [
            'inspectionResult' => [
                'indexStatusResult' => [
                    'verdict' => $verdict,
                    'coverageState' => $verdict === 'PASS' ? 'Submitted and indexed' : 'URL is unknown to Google',
                    'indexingState' => $verdict === 'PASS' ? 'INDEXING_ALLOWED' : 'INDEXING_STATE_UNSPECIFIED',
                    'pageFetchState' => 'SUCCESSFUL',
                    'robotsTxtState' => 'ALLOWED',
                    'lastCrawlTime' => '2026-08-23T12:00:00Z',
                    'googleCanonical' => $googleCanonical,
                    'userCanonical' => $userCanonical,
                ],
            ],
        ];
    }

    private function makeService(?GscUrlInspectionBindingResolver $bindings = null): GscUrlInspectionService
    {
        $bindings ??= $this->fakeBinding(9_400_001);
        $client = new GscUrlInspectionClient($bindings, $this->transport);

        return new GscUrlInspectionService(
            client: $client,
            bindings: $bindings,
            mapper: new GscUrlInspectionHealthMapper,
            locks: new GscUrlInspectionLockService,
            recorder: app(ArticleIndexHealthRecorder::class),
        );
    }

    private function fakeBinding(int $siteId): GscUrlInspectionBindingResolver
    {
        $connection = new \Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
        $connection->forceFill([
            'id' => 1,
            'name' => 'test',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'test-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);

        return new class($siteId, $connection) extends GscUrlInspectionBindingResolver
        {
            public function __construct(
                private readonly int $fixedSiteId,
                private readonly \Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection $connection,
            ) {}

            public function resolveForSite(int $siteId): array
            {
                return [
                    'site_id' => $siteId > 0 ? $siteId : $this->fixedSiteId,
                    'property_uri' => 'sc-domain:example.test',
                    'connection' => $this->connection,
                    'property' => null,
                    'mapping' => null,
                ];
            }

            public function hasBinding(int $siteId): bool
            {
                return true;
            }

            public function resolveAccessToken($connection): string
            {
                return 'test-token';
            }
        };
    }

    private function createPublishedArticle(string $observedStatus = 'publish', ?int $siteId = null): SeoArticle
    {
        $this->seq++;
        $siteId ??= 9_400_000 + ($this->seq % 1000);
        $token = 'gsc-ih-'.uniqid('', true);

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'title' => $token,
            'slug' => $token,
            'type' => 'article',
            'status' => 'publish',
            'language' => 'vi',
        ]);

        WordpressArticleLink::query()->create([
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'wp_post_id' => 8_100_000 + $this->seq,
            'observed_post_status' => $observedStatus,
            'observed_permalink' => 'https://example-'.$siteId.'.test/posts/'.$token.'/',
            'observed_at' => now(),
        ]);

        return $article->fresh(['wordpressLink']) ?? $article;
    }
}
