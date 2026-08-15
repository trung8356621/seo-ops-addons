<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use App\Models\Site;
use Omnichannel\Addons\SearchIntelligence\Jobs\ClassifyDirtyKeywordsJob;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordIntelligenceDebouncer;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

final class KeywordIntelligenceScheduler
{
    public function __construct(
        private readonly KeywordIntelligenceDebouncer $debouncer = new KeywordIntelligenceDebouncer(),
    ) {}

    public function onKeywordChanged(int $siteId, bool $created, bool $phraseChanged): void
    {
        if (! $this->debouncer->shouldDispatch($created, $phraseChanged, 0)) {
            return;
        }
        $this->markDirtyAndDispatch($siteId);
    }

    public function onImportBatch(int $siteId, int $changedCount): void
    {
        if ($this->debouncer->jobsForChangedSet($changedCount) < 1) {
            return;
        }
        $this->markDirtyAndDispatch($siteId);
    }

    public function markDirtyAndDispatch(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site instanceof Site) {
            SiteSyncSiteMeta::putJson($site, KeywordClassificationService::META_DIRTY, [
                'dirty' => true,
                'marked_at' => now()->toIso8601String(),
            ]);
            $progress = SiteSyncSiteMeta::getJson($site, KeywordClassificationService::META_PROGRESS) ?? [];
            if (($progress['status'] ?? '') !== 'running') {
                SiteSyncSiteMeta::putJson($site, KeywordClassificationService::META_PROGRESS, array_merge($progress, [
                    'status' => 'queued',
                    'last_activity_at' => now()->toIso8601String(),
                ]));
            }
        }

        ClassifyDirtyKeywordsJob::dispatch($siteId);
    }
}
