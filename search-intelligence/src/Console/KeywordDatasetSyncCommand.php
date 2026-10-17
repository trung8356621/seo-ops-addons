<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSyncException;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetSyncService;

/**
 * Sync the eligible keyword dataset to Python. Does not cluster or write groups.
 */
final class KeywordDatasetSyncCommand extends Command
{
    protected $signature = 'semantic:keyword-dataset-sync
        {siteId : Site id}
        {--language= : Dataset language. Empty means all languages}';

    protected $description = 'Sync the keyword dataset snapshot to seo-ops-semantic';

    public function handle(KeywordGroupDatasetSyncService $sync): int
    {
        $siteId = (int) $this->argument('siteId');
        $language = $this->option('language');
        $language = is_string($language) && trim($language) !== '' ? trim($language) : null;
        $started = hrtime(true);
        try {
            $result = $sync->syncSite($siteId, $language);
        } catch (KeywordGroupDatasetSyncException $e) {
            $this->line((string) json_encode([
                'status' => 'failed',
                'reason' => $e->reason,
                'http_status' => $e->httpStatus,
                'message' => $e->getMessage(),
                'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'status' => $result->status,
            'revision' => $result->revision,
            'dataset_hash' => $result->datasetHash,
            'keyword_count' => $result->keywordCount,
            'batch_count' => $result->batchCount,
            'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
