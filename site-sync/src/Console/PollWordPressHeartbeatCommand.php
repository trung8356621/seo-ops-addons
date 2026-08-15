<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SiteSync\Jobs\PollWordPressHeartbeatJob;

final class PollWordPressHeartbeatCommand extends Command
{
    protected $signature = 'seo:wp-heartbeat:poll {--site= : Site ID} {--limit=40}';

    protected $description = 'Lightweight WordPress heartbeat poll (plugin alive / version / capabilities).';

    public function handle(): int
    {
        $siteId = (int) $this->option('site');
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = Site::query()->orderBy('id');
        if ($siteId > 0) {
            $query->whereKey($siteId);
        }

        $count = 0;
        foreach ($query->limit($limit)->get() as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
            if ($token === '') {
                continue;
            }
            PollWordPressHeartbeatJob::dispatch((int) $site->id);
            $count++;
        }

        $this->info('Queued '.$count.' heartbeat poll job(s).');

        return self::SUCCESS;
    }
}
