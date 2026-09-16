<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchFoundation\Services\RepairArticleBackedKeywordTargetUrlService;

final class RepairArticleBackedKeywordTargetUrlsCommand extends Command
{
    protected $signature = 'seo:repair-article-backed-keyword-target-urls
        {--site_id= : Optional site filter}
        {--dry-run : Report actions without writing (default when --force not set)}
        {--force : Apply repairs}
        {--json : Print JSON only}';

    protected $description = 'Clear duplicate site.*.target_url on article-backed keywords (WordPress permalink remains SoT)';

    public function handle(RepairArticleBackedKeywordTargetUrlService $repair): int
    {
        $siteId = (int) ($this->option('site_id') ?: 0);
        $force = (bool) $this->option('force');
        $dryRun = ! $force || (bool) $this->option('dry-run');

        if ($force && (bool) $this->option('dry-run')) {
            $dryRun = true;
        }

        $result = $repair->repair($dryRun, $siteId > 0 ? $siteId : null);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'DRY-RUN repair plan' : 'Applied repairs');
        $this->line('Examined: '.(int) $result['examined']);
        $this->line('Cleared (article-backed): '.(int) $result['cleared']);
        $this->line('Skipped (manual/no focus): '.(int) $result['skipped_manual']);
        $this->line('Skipped (cross-site): '.(int) $result['skipped_cross_site']);

        foreach (array_slice($result['cleared_rows'], 0, 40) as $row) {
            $this->line(sprintf(
                '  [%s] keyword=%s site=%s article=%s reason=%s old=%s',
                $dryRun ? 'would-clear' : 'cleared',
                (string) ($row['keyword_id'] ?? ''),
                (string) ($row['site_id'] ?? ''),
                (string) ($row['article_id'] ?? ''),
                (string) ($row['reason'] ?? ''),
                (string) ($row['old_url'] ?? ''),
            ));
        }

        if (! $force) {
            $this->warn('No writes performed. Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
