<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectSiteLinkRepairService;
use Illuminate\Console\Command;

final class RepairContentProjectSiteLinksCommand extends Command
{
    protected $signature = 'seo:content-project:repair-site-links
        {project_id : Content Project ID}
        {--dry-run : Report only; do not write DB (default)}
        {--apply : Apply safe site-link repair}';

    protected $description = 'Diagnose or repair Content Project items whose site/article links drifted onto another domain. Never changes SeoArticle.site_id.';

    public function handle(ContentProjectSiteLinkRepairService $repair): int
    {
        $projectId = (int) $this->argument('project_id');
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($dryRun && $apply) {
            $this->warn('--dry-run wins over --apply; no DB writes will be made.');
            $apply = false;
        }

        $project = SeoProject::query()->with('site')->find($projectId);
        if (! $project instanceof SeoProject) {
            $this->error('Content Project #'.$projectId.' not found.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('DRY-RUN — no DB writes. Add --apply to execute.');
        } else {
            $this->warn('APPLY — will write DB. SeoArticle.site_id is never changed.');
        }

        $report = $repair->repair($project, $apply);
        $projectInfo = $report['project'];

        $this->newLine();
        $this->info(sprintf(
            'Project #%d  site_id=%s  domain=%s  name=%s',
            (int) $projectInfo['id'],
            (string) ($projectInfo['site_id'] ?? ''),
            (string) ($projectInfo['domain'] ?? ''),
            (string) ($projectInfo['name'] ?? ''),
        ));

        $this->newLine();
        $this->line('All active tasks:');
        $this->table(
            [
                'task',
                'date',
                'type',
                'status',
                'task.site',
                'article',
                'art.site',
                'art.domain',
                'title',
                'wp_post',
                'wp_permalink',
                'run_item.article',
                'problem',
            ],
            array_map(static fn (array $row): array => [
                (string) $row['task_id'],
                (string) ($row['target_date'] ?? ''),
                (string) $row['type'],
                (string) $row['status'],
                (string) $row['task_site_id'],
                (string) ($row['article_id'] ?? ''),
                (string) ($row['article_site_id'] ?? ''),
                (string) ($row['article_domain'] ?? ''),
                mb_substr((string) ($row['article_title'] ?? ''), 0, 40),
                (string) ($row['wp_post_id'] ?? ''),
                mb_substr((string) ($row['wp_permalink'] ?? ''), 0, 60),
                (string) ($row['latest_run_item_article_id'] ?? ''),
                (string) ($row['problem'] !== '' ? $row['problem'] : 'ok'),
            ], $report['rows']),
        );

        $mismatches = $report['mismatches'];
        $this->newLine();
        if ($mismatches === []) {
            $this->info('No site-link mismatches.');

            return self::SUCCESS;
        }

        $this->warn('Mismatches: '.count($mismatches));
        $this->table(
            [
                'project',
                'proj.site/domain',
                'task',
                'task.site',
                'article',
                'art.site/domain',
                'run_item.article',
                'problem',
                'proposed / applied',
                'relink',
                'unresolved',
            ],
            array_map(static function (array $row) use ($apply): array {
                $actions = $apply
                    ? implode('; ', $row['applied'] ?? [])
                    : implode('; ', $row['proposed'] ?? []);

                return [
                    (string) $row['project_id'],
                    (string) $row['project_site_id'].'/'.(string) ($row['project_domain'] ?? ''),
                    (string) $row['task_id'],
                    (string) $row['task_site_id'],
                    (string) ($row['article_id'] ?? ''),
                    (string) ($row['article_site_id'] ?? '').'/'.(string) ($row['article_domain'] ?? ''),
                    (string) ($row['latest_run_item_article_id'] ?? ''),
                    (string) $row['problem'],
                    $actions,
                    (string) ($row['relinked_article_id'] ?? ''),
                    ! empty($row['unresolved']) ? 'yes' : 'no',
                ];
            }, $mismatches),
        );

        return self::SUCCESS;
    }
}
