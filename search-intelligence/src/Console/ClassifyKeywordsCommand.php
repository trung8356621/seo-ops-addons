<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Jobs\PushKeywordDictionaryJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\WordPress\Services\WordPressKeywordDictionaryClient;

final class ClassifyKeywordsCommand extends Command
{
    protected $signature = 'seo:keywords:classify
        {--site= : Site ID}
        {--limit=200}
        {--dry-run}
        {--force}
        {--push : Push compact dictionary to WordPress}';

    protected $description = 'Rule-based keyword classification backfill (batch). Does not run production-wide unless --site is set.';

    public function handle(
        KeywordClassificationService $service,
        WordPressKeywordDictionaryClient $dictionaryClient,
    ): int {
        $siteId = (int) $this->option('site');
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $result = $service->classifyBatch($siteId, $limit, $dryRun, $force);
        $this->info('processed='.$result['processed'].' skipped='.$result['skipped']);

        if ($dryRun || $siteId <= 0 || ! $this->option('push')) {
            return self::SUCCESS;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        PushKeywordDictionaryJob::dispatch($siteId);
        $this->info('Queued dictionary push for site '.$siteId);
        unset($dictionaryClient);

        return self::SUCCESS;
    }
}
