<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\WordPress\Services\WordPressKeywordDictionaryClient;

final class PushKeywordDictionaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 40;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function handle(
        KeywordClassificationService $classification,
        WordPressKeywordDictionaryClient $client,
    ): void {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $dictionary = $classification->buildDictionary($this->siteId);
        $previous = SiteSyncSiteMeta::getJson($site, 'seo_keyword_dictionary') ?? [];
        if ((string) ($previous['hash'] ?? '') === (string) $dictionary['hash']) {
            return;
        }

        $result = $client->apply($site, $dictionary);
        SiteSyncSiteMeta::putJson($site, 'seo_keyword_dictionary', [
            'version' => $dictionary['version'],
            'hash' => $dictionary['hash'],
            'cluster_count' => count($dictionary['clusters']),
            'last_push' => $result,
            'pushed_at' => now()->toIso8601String(),
        ]);
        SiteSyncSiteMeta::putJson($site, 'seo_link_analysis_stale', [
            'stale' => true,
            'reason' => 'keyword_dictionary_hash_changed',
            'marked_at' => now()->toIso8601String(),
        ]);
    }
}
