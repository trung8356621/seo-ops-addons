<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class WordpressSyncLeaseWatchdogCommand extends Command
{
    protected $signature = 'seo:wordpress-sync-lease-watchdog
                            {--article= : Force-unlock one article id (meta + lease + cache lock)}
                            {--force : Force-unlock every stuck wp_sync_queue meta/lease}';

    protected $description = 'Mark expired WordPress sync leases as stale, heal orphan wp_sync_queue meta, unlock articles.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ArticleWpSyncLeaseService $lease,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $articleId = (int) $this->option('article');
        if ($articleId > 0) {
            $article = SeoArticle::query()->find($articleId);
            if ($article === null) {
                $this->error('article not found: '.$articleId);

                return self::FAILURE;
            }

            $meta = $lease->forceUnlockArticle($article, 'Force unlock via watchdog --article='.$articleId);
            $this->info(sprintf(
                'force_unlocked article_id=%d status=%s',
                $articleId,
                (string) ($meta['status'] ?? ''),
            ));

            return self::SUCCESS;
        }

        $stats = $lease->recoverExpiredLeases((bool) $this->option('force'));
        $this->info(sprintf(
            'stale_jobs=%d orphan_metas=%d force_unlocked=%d',
            (int) ($stats['stale_jobs'] ?? 0),
            (int) ($stats['orphan_metas'] ?? 0),
            (int) ($stats['force_unlocked'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
