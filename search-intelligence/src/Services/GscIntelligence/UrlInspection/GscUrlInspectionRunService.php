<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Jobs\ProcessGscUrlInspectionRunJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscUrlInspectionRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscUrlInspectionRunItem;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexCanonicalUrlResolver;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthEligibility;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthPolicy;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthQueryService;
use RuntimeException;
use Throwable;

/**
 * Batch URL Inspection: create run → queue job → per-URL inspect → finalize.
 */
final class GscUrlInspectionRunService
{
    public function __construct(
        private readonly GscUrlInspectionService $inspection = new GscUrlInspectionService,
        private readonly GscUrlInspectionBindingResolver $bindings = new GscUrlInspectionBindingResolver,
        private readonly ArticleIndexHealthEligibility $eligibility = new ArticleIndexHealthEligibility,
        private readonly ArticleIndexCanonicalUrlResolver $urls = new ArticleIndexCanonicalUrlResolver,
        private readonly ArticleIndexHealthQueryService $indexHealthQuery = new ArticleIndexHealthQueryService,
        private readonly bool $dispatchAsync = true,
    ) {}

    /**
     * Queue inspection for explicit article IDs (bounded).
     *
     * @param  list<int>  $articleIds
     * @return array<string, mixed>
     */
    public function queueForArticles(int $siteId, array $articleIds, ?int $actorId = null, ?int $limit = null): array
    {
        $this->assertRunTables();
        $limit = GscUrlInspectionPolicy::clampLimit($limit ?? GscUrlInspectionPolicy::DEFAULT_BATCH_LIMIT);

        try {
            $binding = $this->bindings->resolveForSite($siteId);
        } catch (GscUrlInspectionApiException $e) {
            return [
                'ok' => false,
                'queued' => false,
                'run_id' => null,
                'public_ref' => null,
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
            ];
        }

        $ids = $this->normalizeIds($articleIds);
        $selected = [];
        foreach ($ids as $id) {
            if (count($selected) >= $limit) {
                break;
            }
            $article = SeoArticle::query()->with(['wordpressLink', 'articleMetas'])->find($id);
            if (! $article instanceof SeoArticle) {
                continue;
            }
            if ((int) ($article->site_id ?? 0) !== $siteId) {
                continue;
            }
            if (! $this->eligibility->isEligible($article)) {
                continue;
            }
            $url = $this->urls->resolve($article);
            if ($url === null) {
                continue;
            }
            $selected[] = ['article_id' => $id, 'url' => $url];
        }

        if ($selected === []) {
            return [
                'ok' => false,
                'queued' => false,
                'run_id' => null,
                'public_ref' => null,
                'error_code' => 'gsc.no_eligible_articles',
                'error_message' => 'No eligible published articles to inspect.',
            ];
        }

        return $this->createAndDispatch($siteId, (string) $binding['property_uri'], $selected, $actorId);
    }

    /**
     * Queue due Index Health articles that are GSC-eligible for this site.
     *
     * @return array<string, mixed>
     */
    public function queueDue(int $siteId, ?int $actorId = null, ?int $limit = null): array
    {
        $this->assertRunTables();
        $limit = GscUrlInspectionPolicy::clampLimit($limit ?? GscUrlInspectionPolicy::DEFAULT_BATCH_LIMIT);

        try {
            $this->bindings->resolveForSite($siteId);
        } catch (GscUrlInspectionApiException $e) {
            return [
                'ok' => false,
                'queued' => false,
                'run_id' => null,
                'public_ref' => null,
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
            ];
        }

        $page = $this->indexHealthQuery->paginate([
            'site_id' => $siteId,
            'tab' => 'needs_review',
            'per_page' => $limit,
        ]);

        $ids = [];
        foreach ($page->items() as $row) {
            if (is_array($row) && isset($row['article_id'])) {
                $ids[] = (int) $row['article_id'];
            }
        }

        return $this->queueForArticles($siteId, $ids, $actorId, $limit);
    }

    /**
     * Process a queued run synchronously (job handle / tests).
     *
     * @return array<string, mixed>
     */
    public function processRun(int $runId): array
    {
        $this->assertRunTables();
        $run = SeoGscUrlInspectionRun::query()->find($runId);
        if (! $run instanceof SeoGscUrlInspectionRun) {
            throw new RuntimeException('Inspection run not found.');
        }

        if (in_array((string) $run->status, ['completed', 'partial', 'failed'], true)) {
            return $this->summarizeRun($run);
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? Carbon::now()->utc(),
        ])->save();

        $actorId = $run->created_by !== null ? (int) $run->created_by : null;
        $pausedForQuota = false;

        $items = SeoGscUrlInspectionRunItem::query()
            ->where('run_id', $runId)
            ->where('status', 'queued')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if ($pausedForQuota) {
                $item->forceFill([
                    'status' => 'skipped',
                    'error_code' => 'gsc.rate_limited',
                    'error_message' => 'Inspection paused because Google API quota/rate limit was reached.',
                ])->save();
                continue;
            }

            $item->forceFill(['status' => 'running'])->save();
            $result = $this->inspection->inspectArticle((int) $item->article_id, $actorId);

            if (($result['ok'] ?? false) === true) {
                $checkStatus = (string) ($result['check_status'] ?? ArticleIndexCheckStatus::Unknown->value);
                $item->forceFill([
                    'status' => 'recorded',
                    'url' => $result['url'] ?? $item->url,
                    'check_status' => $checkStatus,
                    'check_id' => $result['check_id'] ?? null,
                    'diagnostics' => $result['diagnostics'] ?? null,
                    'error_code' => null,
                    'error_message' => null,
                ])->save();
                $this->bumpCounters($run, $checkStatus, true);
                continue;
            }

            $rateLimited = (bool) ($result['rate_limited'] ?? false)
                || (($result['error_code'] ?? '') === 'gsc.rate_limited');

            $item->forceFill([
                'status' => 'failed',
                'url' => $result['url'] ?? $item->url,
                'error_code' => $result['error_code'] ?? 'gsc.failed',
                'error_message' => $result['error_message'] ?? 'Inspection failed.',
            ])->save();
            $this->bumpCounters($run, null, false);

            if ($rateLimited) {
                $pausedForQuota = true;
                $run->forceFill([
                    'error_code' => 'gsc.rate_limited',
                    'error_message' => 'Inspection paused because Google API quota/rate limit was reached.',
                ])->save();
            }
        }

        $run->refresh();
        $failed = (int) $run->failed;
        $inspected = (int) $run->inspected;
        $requested = (int) $run->requested;
        $skipped = SeoGscUrlInspectionRunItem::query()
            ->where('run_id', $runId)
            ->where('status', 'skipped')
            ->count();

        $status = match (true) {
            $inspected === 0 && $failed > 0 => 'failed',
            $failed > 0 || $skipped > 0 || $inspected < $requested => 'partial',
            default => 'completed',
        };

        $run->forceFill([
            'status' => $status,
            'finished_at' => Carbon::now()->utc(),
        ])->save();

        return $this->summarizeRun($run->fresh() ?? $run);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestActiveRunForSite(int $siteId): ?array
    {
        if (! $this->runTablesReady() || $siteId <= 0) {
            return null;
        }

        $run = SeoGscUrlInspectionRun::query()
            ->where('site_id', $siteId)
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('id')
            ->first();

        return $run instanceof SeoGscUrlInspectionRun ? $this->summarizeRun($run) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summarizeByPublicRef(string $publicRef): ?array
    {
        if (! $this->runTablesReady()) {
            return null;
        }

        $run = SeoGscUrlInspectionRun::query()->where('public_ref', $publicRef)->first();

        return $run instanceof SeoGscUrlInspectionRun ? $this->summarizeRun($run) : null;
    }

    /**
     * Sync path for tests — inspect without queue.
     *
     * @param  list<int>  $articleIds
     * @return array<string, mixed>
     */
    public function processSynchronously(int $siteId, array $articleIds, ?int $actorId = null, ?int $limit = null): array
    {
        $queued = $this->queueForArticles($siteId, $articleIds, $actorId, $limit);
        if (! ($queued['ok'] ?? false) || ! isset($queued['run_id'])) {
            return $queued;
        }

        $summary = $this->processRun((int) $queued['run_id']);

        return array_merge($queued, $summary, ['queued' => false, 'ok' => true]);
    }

    /**
     * @param  list<array{article_id: int, url: string}>  $selected
     * @return array<string, mixed>
     */
    private function createAndDispatch(int $siteId, string $propertyUri, array $selected, ?int $actorId): array
    {
        $publicRef = 'gscuir_'.Str::lower((string) Str::ulid());
        $run = SeoGscUrlInspectionRun::query()->create([
            'public_ref' => $publicRef,
            'site_id' => $siteId,
            'property_uri' => $propertyUri,
            'status' => 'queued',
            'requested' => count($selected),
            'inspected' => 0,
            'indexed' => 0,
            'not_indexed' => 0,
            'unknown' => 0,
            'failed' => 0,
            'created_by' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'meta' => ['recheck_months' => ArticleIndexHealthPolicy::RECHECK_MONTHS],
        ]);

        foreach ($selected as $row) {
            SeoGscUrlInspectionRunItem::query()->create([
                'run_id' => (int) $run->id,
                'article_id' => (int) $row['article_id'],
                'url' => (string) $row['url'],
                'status' => 'queued',
            ]);
        }

        if ($this->dispatchAsync) {
            try {
                ProcessGscUrlInspectionRunJob::dispatch((int) $run->id);
            } catch (Throwable) {
                // Fall through to sync if queue unavailable (local/dev).
                $this->processRun((int) $run->id);
                $fresh = $run->fresh() ?? $run;

                return array_merge($this->summarizeRun($fresh), [
                    'ok' => true,
                    'queued' => false,
                    'run_id' => (int) $run->id,
                    'public_ref' => $publicRef,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }
        }

        return [
            'ok' => true,
            'queued' => true,
            'run_id' => (int) $run->id,
            'public_ref' => $publicRef,
            'requested' => count($selected),
            'inspected' => 0,
            'indexed' => 0,
            'not_indexed' => 0,
            'unknown' => 0,
            'failed' => 0,
            'status' => 'queued',
            'error_code' => null,
            'error_message' => null,
        ];
    }

    private function bumpCounters(SeoGscUrlInspectionRun $run, ?string $checkStatus, bool $success): void
    {
        if ($success) {
            $run->inspected = (int) $run->inspected + 1;
            match ($checkStatus) {
                ArticleIndexCheckStatus::Indexed->value => $run->indexed = (int) $run->indexed + 1,
                ArticleIndexCheckStatus::NotIndexed->value => $run->not_indexed = (int) $run->not_indexed + 1,
                default => $run->unknown = (int) $run->unknown + 1,
            };
        } else {
            $run->failed = (int) $run->failed + 1;
        }
        $run->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeRun(SeoGscUrlInspectionRun $run): array
    {
        return [
            'ok' => in_array((string) $run->status, ['completed', 'partial', 'queued', 'running'], true),
            'queued' => in_array((string) $run->status, ['queued', 'running'], true),
            'run_id' => (int) $run->id,
            'public_ref' => (string) $run->public_ref,
            'site_id' => (int) $run->site_id,
            'status' => (string) $run->status,
            'requested' => (int) $run->requested,
            'inspected' => (int) $run->inspected,
            'indexed' => (int) $run->indexed,
            'not_indexed' => (int) $run->not_indexed,
            'unknown' => (int) $run->unknown,
            'failed' => (int) $run->failed,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
        ];
    }

    /**
     * @param  list<int|string>  $articleIds
     * @return list<int>
     */
    private function normalizeIds(array $articleIds): array
    {
        $ids = [];
        foreach ($articleIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }

        return array_values($ids);
    }

    private function assertRunTables(): void
    {
        if (! $this->runTablesReady()) {
            throw new RuntimeException('GSC URL Inspection run tables missing — run local migration.');
        }
    }

    private function runTablesReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('seo_gsc_url_inspection_runs')
                && Schema::connection('omi_seo_ai')->hasTable('seo_gsc_url_inspection_run_items');
        } catch (Throwable) {
            return false;
        }
    }
}
