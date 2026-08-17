<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectLegacyTaskWpSearchService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTaskHistoryForensicService;
use App\Models\Site;
use Illuminate\Console\Command;

final class DiagnoseContentProjectTaskHistoryCommand extends Command
{
    protected $signature = 'seo:content-project:diagnose-task-history
        {task_id : SeoProjectTask ID}
        {--skip-wp : Do not call WordPress}';

    protected $description = 'Read-only forensic provenance dump for a Content Project task (legacy corruption).';

    public function handle(
        ContentProjectTaskHistoryForensicService $forensic,
        ContentProjectLegacyTaskWpSearchService $wpSearch,
    ): int {
        $taskId = (int) $this->argument('task_id');
        $report = $forensic->diagnose($taskId);
        if (($report['ok'] ?? false) !== true) {
            $this->error((string) ($report['error'] ?? 'diagnose_failed'));

            return self::FAILURE;
        }

        $task = $report['task'];
        $project = $report['project'];
        $article = $report['current_article'];
        $prompt = $report['historical_prompt'];
        $order = $report['creation_order'];

        $this->info(sprintf(
            'Task #%d  project=%s  project.site=%s/%s  task.site=%s  type=%s  status=%s',
            (int) $task['id'],
            (string) ($project['id'] ?? ''),
            (string) ($project['site_id'] ?? ''),
            (string) ($project['domain'] ?? ''),
            (string) ($task['site_id'] ?? ''),
            (string) $task['type'],
            (string) $task['status'],
        ));
        $this->line('keyword: '.(string) $task['keyword']);
        $this->line('planned title: '.(string) $task['title']);
        $this->line('source_key: '.(string) $task['source_key']);
        $this->line('task.article_id: '.(string) ($task['article_id'] ?? ''));
        $this->line('created_at: '.(string) ($task['created_at'] ?? '').'  connected_at: '.(string) ($task['connected_at'] ?? ''));

        $this->newLine();
        $this->info('CURRENT article_id target');
        if (! is_array($article)) {
            $this->warn('No local article row for this numeric id.');
        } else {
            $this->table(
                ['id', 'site', 'domain', 'title', 'slug', 'created_at', 'wp_post', 'origin'],
                [[
                    (string) $article['id'],
                    (string) $article['site_id'],
                    (string) $article['domain'],
                    mb_substr((string) $article['title'], 0, 50),
                    (string) $article['slug'],
                    (string) ($article['created_at'] ?? ''),
                    (string) ($article['wp_post_id'] ?? ''),
                    (string) ($article['automation_origin_type'] ?? '').':'.(string) ($article['automation_origin_id'] ?? ''),
                ]],
            );
            $this->line('temporally/semantically consistent with task: '.($report['current_article_temporally_semantically_consistent'] ? 'yes' : 'NO'));
            $this->line('independent provenance to current article: '.($report['independent_provenance_to_current_article'] ? 'yes' : 'NO'));
        }

        $this->newLine();
        $this->info('Prompt keyword');
        $this->line('historical focus_keyword: '.json_encode($prompt['focus_keyword'], JSON_UNESCAPED_UNICODE));
        $this->line('historical post_title: '.json_encode($prompt['post_title'], JSON_UNESCAPED_UNICODE));
        $this->line('generated title: '.json_encode($prompt['generated_title'], JSON_UNESCAPED_UNICODE));
        $this->line('source: '.(string) $prompt['source']);
        $this->warn('classification: '.(string) $prompt['classification']);

        $this->newLine();
        $this->info('Creation order: '.(string) $order['classification']);
        $this->line('first_prompt_at='.(string) ($order['first_prompt_at'] ?? '').' bound_at='.(string) ($order['article_bound_at'] ?? '').' current_article_created_at='.(string) ($order['current_article_created_at'] ?? ''));
        $this->line('collision: '.(string) $report['collision']);

        $this->newLine();
        $this->info('Timeline');
        $this->table(
            ['at', 'event', 'detail'],
            array_map(static fn (array $row): array => [
                (string) ($row['at'] ?? ''),
                (string) $row['event'],
                mb_substr((string) $row['detail'], 0, 80),
            ], $report['timeline'] ?? []),
        );

        if (! $this->option('skip-wp') && $project['site_id']) {
            $site = Site::query()->find((int) $project['site_id']);
            if ($site instanceof Site) {
                $this->newLine();
                $this->info('WordPress candidates on '.$site->domain);
                $candidates = $wpSearch->search($site, $report);
                if ($candidates === []) {
                    $this->line('No WP candidates returned.');
                } else {
                    $this->table(
                        ['ok', 'wp_post', 'title', 'slug', 'status', 'evidence', 'permalink'],
                        array_map(static fn (array $row): array => [
                            ! empty($row['ok']) ? 'yes' : 'no',
                            (string) ($row['wp_post_id'] ?? ''),
                            mb_substr((string) ($row['title'] ?? ''), 0, 40),
                            (string) ($row['slug'] ?? ''),
                            (string) ($row['status'] ?? ($row['message'] ?? '')),
                            (string) ($row['evidence'] ?? ''),
                            mb_substr((string) ($row['permalink'] ?? ''), 0, 50),
                        ], $candidates),
                    );
                }
                $pick = $wpSearch->pickStrongUnambiguous(
                    $candidates,
                    (string) $task['keyword'],
                    (string) $task['title'] ?: null,
                );
                $this->line('WP strong pick: '.(string) ($pick['status'] ?? 'none'));
            }
        }

        return self::SUCCESS;
    }
}
