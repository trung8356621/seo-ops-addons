<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\LinkHealth;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Jobs\ProcessLinkHealthBatchJob;
use Omnichannel\Addons\SiteSync\Models\SeoLinkHealthRun;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler;
use Throwable;

final class LinkHealthRunService
{
    public function __construct(
        private readonly WordPressSiteSyncClient $client,
        private readonly SiteLinkCatalogReconciler $catalog,
    ) {}

    public function start(Site $site): SeoLinkHealthRun
    {
        $run = SeoLinkHealthRun::query()->create([
            'site_id' => (int) $site->id,
            'status' => SeoLinkHealthRun::STATUS_QUEUED,
            'cursor' => 0,
            'posts_processed' => 0,
            'links_checked' => 0,
            'broken_candidates' => 0,
        ]);

        ProcessLinkHealthBatchJob::dispatch((int) $run->id);

        return $run;
    }

    public function processNext(SeoLinkHealthRun $run): SeoLinkHealthRun
    {
        if (in_array((string) $run->status, [
            SeoLinkHealthRun::STATUS_COMPLETED,
            SeoLinkHealthRun::STATUS_FAILED,
            SeoLinkHealthRun::STATUS_CANCELLED,
        ], true)) {
            return $run;
        }

        $site = Site::query()->find((int) $run->site_id);
        if (! $site instanceof Site) {
            $run->forceFill([
                'status' => SeoLinkHealthRun::STATUS_FAILED,
                'error_message' => 'Site missing',
                'finished_at' => now(),
            ])->save();

            return $run;
        }

        if ($run->started_at === null) {
            $run->started_at = now();
        }
        $run->status = SeoLinkHealthRun::STATUS_RUNNING;
        $run->save();

        try {
            $result = $this->client->fetchLinkHealthBatch($site, (int) $run->cursor);
            if (! ($result['success'] ?? false) || ! isset($result['batch'])) {
                $run->forceFill([
                    'status' => SeoLinkHealthRun::STATUS_FAILED,
                    'error_message' => (string) ($result['message'] ?? 'Link health batch failed'),
                    'finished_at' => now(),
                ])->save();

                return $run;
            }

            $batch = $result['batch'];
            $links = $this->flattenLinks($batch);
            if ($links !== []) {
                $this->catalog->reconcileWordPressLinks($site, $links);
            }

            $run->cursor = (int) ($batch['next_cursor'] ?? $run->cursor);
            $run->posts_processed = (int) $run->posts_processed + (int) ($batch['posts_in_batch'] ?? 0);
            $run->links_checked = (int) $run->links_checked + (int) ($batch['links_checked'] ?? 0);
            $run->broken_candidates = (int) $run->broken_candidates + (int) ($batch['broken_candidates'] ?? 0);
            $run->total_posts = isset($batch['total_posts']) ? (int) $batch['total_posts'] : $run->total_posts;
            $run->summary = [
                'last_batch_posts' => (int) ($batch['posts_in_batch'] ?? 0),
                'done' => (bool) ($batch['done'] ?? false),
            ];

            if ((bool) ($batch['done'] ?? false)) {
                $run->status = SeoLinkHealthRun::STATUS_COMPLETED;
                $run->finished_at = now();
                $run->save();

                return $run;
            }

            $run->save();
            ProcessLinkHealthBatchJob::dispatch((int) $run->id);

            return $run;
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => SeoLinkHealthRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            return $run;
        }
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return list<array<string, mixed>>
     */
    private function flattenLinks(array $batch): array
    {
        $out = [];
        $items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $wpId = (int) ($item['wp_post_id'] ?? 0);
            $links = is_array($item['links'] ?? null) ? $item['links'] : [];
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $out[] = [
                    'wordpress_id' => $wpId,
                    'url' => (string) ($link['url'] ?? ''),
                    'canonical' => (string) ($link['url'] ?? ''),
                    'status' => 'publish',
                    'type' => 'article',
                    'content_hash' => (string) ($item['content_hash'] ?? ''),
                    'meta' => [
                        'health_status' => (string) ($link['status'] ?? ''),
                        'link_type' => (string) ($link['link_type'] ?? ''),
                        'anchor' => (string) ($link['anchor'] ?? ''),
                        'target_post_id' => (int) ($link['target_post_id'] ?? 0),
                    ],
                ];
            }
        }

        return $out;
    }
}
