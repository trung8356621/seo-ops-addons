<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

final class ClassifyDirtyKeywordsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'keyword-intel-classify-'.$this->siteId;
    }

    public function handle(KeywordClassificationService $classification): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        SiteSyncSiteMeta::putJson($site, KeywordClassificationService::META_PROGRESS, array_merge(
            SiteSyncSiteMeta::getJson($site, KeywordClassificationService::META_PROGRESS) ?? [],
            ['status' => 'running', 'last_activity_at' => now()->toIso8601String()],
        ));

        $processed = 0;
        $loops = 0;
        do {
            $result = $classification->classifyBatch($this->siteId, 500, false, false);
            $processed += $result['processed'];
            $loops++;
        } while ($result['dirty_remaining'] > 0 && $result['processed'] > 0 && $loops < 200);

        if ($processed > 0) {
            PushKeywordDictionaryJob::dispatch($this->siteId);
        }
    }
}
