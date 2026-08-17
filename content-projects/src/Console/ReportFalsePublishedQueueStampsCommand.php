<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Report / optionally clear leftover publish_published_at stamps on Content Project
 * working-set rows that are not actually live on WordPress.
 *
 * Workflow is derived, not stored. The corrupt stored field is the publisher stamp
 * left behind after Return to Content Project. Dry-run by default.
 */
final class ReportFalsePublishedQueueStampsCommand extends Command
{
    protected $signature = 'content-project:report-false-published-stamps
        {--apply : Clear leftover stamps (off by default)}
        {--project-id= : Limit to one Content Project}
        {--limit=50 : Max rows to list}';

    protected $description = 'Report leftover publish_published_at stamps that are not WordPress-live evidence. Does not unpublish WP.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $projectId = (int) ($this->option('project-id') ?? 0);
        $limit = max(1, (int) ($this->option('limit') ?? 50));

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_published_at')) {
            $this->warn('Column publish_published_at is missing — nothing to report.');

            return self::SUCCESS;
        }

        $query = SeoProjectTask::query()
            ->whereNull('archived_at')
            ->whereNotNull('publish_published_at');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
            $query->whereNull('publishing_queued_at');
        }
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        try {
            $query->with(['article.wordpressLink']);
        } catch (Throwable) {
            // Relation optional for listing.
        }

        $suspect = [];
        foreach ($query->get() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $article = $task->relationLoaded('article') ? $task->article : null;
            $observed = ContentProjectPublishedEvidence::resolveObservedPostStatus(
                $article instanceof SeoArticle ? $article : null,
            );
            if ($observed !== null && \Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus::isLiveOnSite($observed)) {
                continue;
            }
            $suspect[] = $task;
        }

        $this->info($apply ? 'APPLY — will clear leftover stamps.' : 'DRY-RUN — no DB writes. Add --apply to clear.');
        $this->line('Suspect leftover stamps (not WP-live): '.count($suspect));

        $rows = [];
        foreach (array_slice($suspect, 0, $limit) as $task) {
            $article = $task->relationLoaded('article') ? $task->article : null;
            $observed = ContentProjectPublishedEvidence::resolveObservedPostStatus(
                $article instanceof SeoArticle ? $article : null,
            );
            $rows[] = [
                (string) $task->getKey(),
                (string) ($task->project_id ?? ''),
                (string) ($task->publish_queue_status ?? 'none'),
                $observed ?? '—',
                (string) ($task->getAttributes()['publish_published_at'] ?? ''),
            ];
        }
        if ($rows !== []) {
            $this->table(['task_id', 'project_id', 'queue_status', 'observed', 'publish_published_at'], $rows);
        }

        if (! $apply || $suspect === []) {
            return self::SUCCESS;
        }

        $ids = array_map(static fn (SeoProjectTask $task): int => (int) $task->getKey(), $suspect);
        $updated = SeoProjectTask::query()->whereIn('id', $ids)->update(['publish_published_at' => null]);
        $this->info('Cleared publish_published_at on '.$updated.' row(s). Observed WP state was not changed.');

        return self::SUCCESS;
    }
}
