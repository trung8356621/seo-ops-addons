<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;

/**
 * Read-only Topic seed-source impact preview — never mutates Topics.
 */
final class PreviewTopicSeedEvidenceCommand extends Command
{
    protected $signature = 'seo:topics-seed-preview
        {site_id : Site id to preview}';

    protected $description = 'Read-only preview of curated Domain Link List + product_cat Topic seeds (no Topic mutation)';

    public function handle(TopicSeedResolver $seeds): int
    {
        $siteId = (int) $this->argument('site_id');
        if ($siteId <= 0) {
            $this->error('site_id required');

            return self::FAILURE;
        }

        if (! TopicReclusterService::tablesReady()) {
            $this->error('Topic Core tables missing — run migrations first.');

            return self::FAILURE;
        }

        $preview = $seeds->previewSeedEvidence($siteId);
        $this->info('Topic seed evidence preview (read-only) for site '.$siteId);
        foreach ($preview as $key => $value) {
            $this->line(sprintf('  %s: %s', $key, $value === null ? 'n/a' : (string) $value));
        }
        $this->warn('Does NOT recluster, dissolve, or delete Topics.');

        return self::SUCCESS;
    }
}
