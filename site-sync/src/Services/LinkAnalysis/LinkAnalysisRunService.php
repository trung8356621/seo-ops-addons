<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\LinkAnalysis;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Jobs\ProcessLinkAnalysisBatchJob;
use Omnichannel\Addons\SiteSync\Models\SeoLinkAnalysisRun;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

final class LinkAnalysisRunService
{
    public function __construct(
        private readonly WordPressSiteSyncClient $client,
    ) {}

    public function start(Site $site): SeoLinkAnalysisRun
    {
        $run = SeoLinkAnalysisRun::query()->create([
            'site_id' => (int) $site->id,
            'status' => SeoLinkAnalysisRun::STATUS_QUEUED,
            'cursor' => 0,
        ]);
        ProcessLinkAnalysisBatchJob::dispatch((int) $run->id);

        return $run;
    }

    public function processNext(SeoLinkAnalysisRun $run): SeoLinkAnalysisRun
    {
        if (in_array((string) $run->status, [
            SeoLinkAnalysisRun::STATUS_COMPLETED,
            SeoLinkAnalysisRun::STATUS_FAILED,
            SeoLinkAnalysisRun::STATUS_CANCELLED,
        ], true)) {
            return $run;
        }

        $site = Site::query()->find((int) $run->site_id);
        if (! $site instanceof Site) {
            $run->forceFill([
                'status' => SeoLinkAnalysisRun::STATUS_FAILED,
                'error_message' => 'Site missing',
                'finished_at' => now(),
            ])->save();

            return $run;
        }

        if ($run->started_at === null) {
            $run->started_at = now();
        }
        $run->status = SeoLinkAnalysisRun::STATUS_RUNNING;
        $run->save();

        $result = $this->client->fetchLinkAnalysisBatch($site, (int) $run->cursor);
        if (! ($result['success'] ?? false) || ! isset($result['batch'])) {
            $run->forceFill([
                'status' => SeoLinkAnalysisRun::STATUS_FAILED,
                'error_message' => (string) ($result['message'] ?? 'Link analysis batch failed'),
                'finished_at' => now(),
            ])->save();

            return $run;
        }

        $batch = $result['batch'];
        $run->cursor = (int) ($batch['next_cursor'] ?? $run->cursor);
        $run->processed_posts = (int) $run->processed_posts + (int) ($batch['posts_in_batch'] ?? 0);
        $run->total_posts = isset($batch['total_posts']) ? (int) $batch['total_posts'] : $run->total_posts;
        $run->opportunities = (int) ($batch['opportunities'] ?? $run->opportunities);
        $run->orphan_pages = (int) ($batch['orphan_pages'] ?? $run->orphan_pages);
        $run->internal_links = (int) ($batch['internal_links'] ?? $run->internal_links);
        $run->summary = [
            'dictionary_version' => $batch['dictionary_version'] ?? null,
            'done' => (bool) ($batch['done'] ?? false),
        ];

        if ((bool) ($batch['done'] ?? false)) {
            $run->status = SeoLinkAnalysisRun::STATUS_COMPLETED;
            $run->finished_at = now();
            SiteSyncSiteMeta::putJson($site, 'seo_link_analysis_snapshot', [
                'indexed_posts' => (int) ($batch['indexed_posts'] ?? $run->processed_posts),
                'internal_links' => (int) $run->internal_links,
                'orphan_pages' => (int) $run->orphan_pages,
                'opportunities' => (int) $run->opportunities,
                'broken_links' => (int) ($batch['broken_links'] ?? 0),
                'dictionary_version' => $batch['dictionary_version'] ?? null,
                'last_analyzed_at' => now()->toIso8601String(),
            ]);
            $run->save();

            return $run;
        }

        $run->save();
        ProcessLinkAnalysisBatchJob::dispatch((int) $run->id);

        return $run;
    }
}
