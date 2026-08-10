<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use App\Support\RuntimeLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Canonical Laravel-scheduler entry for Content Project publishing dispatch.
 *
 * Delegates to ContentProjectPublishingQueueRunner (scheduled_publish_at on tasks).
 * Also covers legacy non-project articles.status=scheduled via business event emit.
 * Does not publish WordPress directly.
 */
final class PublishScheduledArticlesCommand extends Command
{
    protected $signature = 'seo:publish-scheduled-articles';

    protected $description = 'Dispatch due Content Project publish queue (+ legacy non-project scheduled articles). No direct WP publish.';

    public function handle(ScheduledArticlePublishRunner $runner): int
    {
        try {
            $stats = $runner->run();
        } catch (Throwable $e) {
            $this->error(sprintf(
                '[seo:publish-scheduled-articles] %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            RuntimeLogger::report($e, [
                'command' => 'seo:publish-scheduled-articles',
            ]);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'processed=%d claimed_count=%d dispatched_count=%d publisher_started_count=%d published_confirmed_count=%d retry_wait_count=%d failed_count=%d skipped=%d bootstrap_failed=%d connections_attempted=%d connections_skipped=%d',
            $stats['processed'],
            $stats['claimed'] ?? $stats['claimed_count'] ?? 0,
            $stats['dispatched'] ?? $stats['dispatched_count'] ?? 0,
            $stats['publisher_started'] ?? $stats['publisher_started_count'] ?? 0,
            $stats['published_confirmed'] ?? $stats['published_confirmed_count'] ?? $stats['published'] ?? 0,
            $stats['retry_scheduled'] ?? $stats['retry_wait_count'] ?? 0,
            $stats['failed'] ?? $stats['failed_count'] ?? 0,
            $stats['skipped'] ?? 0,
            $stats['bootstrap_failed'] ?? 0,
            $stats['connections_attempted'] ?? 0,
            $stats['connections_skipped'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
