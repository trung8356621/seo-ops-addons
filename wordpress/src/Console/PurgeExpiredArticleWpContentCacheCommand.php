<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\WordPress\Services\ArticleWpContentCacheService;

final class PurgeExpiredArticleWpContentCacheCommand extends Command
{
    protected $signature = 'wordpress:purge-expired-article-wp-content-cache';

    protected $description = 'Delete expired temporary WP content cache rows (editor open cache, TTL 7 days).';

    public function handle(ArticleWpContentCacheService $cache): int
    {
        $expired = $cache->purgeExpired();
        $withBody = $cache->purgeWhereBodyPresent();
        $this->info("Purged expired={$expired}, body_present={$withBody}");

        return self::SUCCESS;
    }
}
