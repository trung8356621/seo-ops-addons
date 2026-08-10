<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReconcilePublishingQueueRemoteTasksCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Reconcile CP publishing tasks that already exist on WordPress.
 *
 * Example:
 *   php artisan seo:publishing:reconcile-tasks --ids=438,441 --dry-run
 *   php artisan seo:publishing:reconcile-tasks --ids=438 --apply --resync-content
 */
final class ReconcilePublishingQueueTasksCommand extends Command
{
    protected $signature = 'seo:publishing:reconcile-tasks
        {--ids= : Comma-separated seo_project_tasks ids}
        {--dry-run : Classify only (default)}
        {--apply : Apply markPublished for remote_published_matching}
        {--resync-content : Also push repaired HTML to existing WP posts}';

    protected $description = 'Reconcile Publishing Queue tasks against WordPress evidence (safe, no duplicate create).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectCommandBus $bus,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $idsRaw = trim((string) $this->option('ids'));
        if ($idsRaw === '') {
            $idsRaw = '438,441,442,453,454,455,456,457,458,459,461,462,463,464';
        }

        $ids = array_values(array_filter(array_map(
            static fn (string $v): int => (int) trim($v),
            explode(',', $idsRaw),
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            $this->error('No task ids.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;
        if ((bool) $this->option('dry-run') && ! $apply) {
            $dryRun = true;
        }

        $this->info(sprintf(
            'Reconciling %d task(s) dry_run=%s resync_content=%s via CommandBus',
            count($ids),
            $dryRun ? 'yes' : 'no',
            $this->option('resync-content') ? 'yes' : 'no',
        ));

        $result = $bus->dispatch(
            new ReconcilePublishingQueueRemoteTasksCommand(
                taskIds: $ids,
                dryRun: $dryRun,
                resyncContent: (bool) $this->option('resync-content'),
            ),
            ActorContext::system('seo:publishing:reconcile-tasks'),
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->metadata['results'] ?? [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->line(sprintf(
                'task=%d article=%s class=%s action=%s applied=%s wp_post_id=%s msg=%s',
                (int) ($row['task_id'] ?? 0),
                (string) ($row['article_id'] ?? '—'),
                (string) ($row['classification'] ?? '—'),
                (string) ($row['action'] ?? '—'),
                ! empty($row['applied']) ? 'yes' : 'no',
                (string) ($row['wp_post_id'] ?? '—'),
                (string) ($row['message'] ?? ''),
            ));
        }

        /** @var list<array<string, mixed>> $resyncRows */
        $resyncRows = $result->metadata['resync_results'] ?? [];
        if ($resyncRows !== []) {
            $this->newLine();
            $this->info('Content resync:');
            foreach ($resyncRows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $this->line(sprintf(
                    'task=%d article=%s ok=%s wp_post_id=%s msg=%s',
                    (int) ($r['task_id'] ?? 0),
                    (string) ($r['article_id'] ?? '—'),
                    ! empty($r['ok']) ? 'yes' : 'no',
                    (string) ($r['wp_post_id'] ?? '—'),
                    (string) ($r['message'] ?? ''),
                ));
            }
        }

        $counts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = (string) ($row['classification'] ?? 'unknown');
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }
        $this->newLine();
        foreach ($counts as $class => $n) {
            $this->line("summary {$class}={$n}");
        }

        if (! $result->success) {
            $this->error($result->message);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
