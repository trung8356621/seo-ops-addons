<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchFoundation\Services\Audit\CrossSiteRelationAuditService;

final class AuditCrossSiteRelationsCommand extends Command
{
    protected $signature = 'seo:audit-cross-site-relations
        {--site_id= : Optional site filter}
        {--json : Print JSON only}
        {--limit=50 : Max findings to print in table mode}';

    protected $description = 'READ-ONLY audit of cross-site keyword/article/link contamination';

    public function handle(CrossSiteRelationAuditService $audit): int
    {
        $siteId = (int) ($this->option('site_id') ?: 0);
        $report = $audit->audit($siteId > 0 ? $siteId : null);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Cross-site relation audit'.($siteId > 0 ? " (site_id={$siteId})" : ''));
        foreach ($report['counts'] as $key => $count) {
            $this->line(sprintf('  %s = %d', $key, (int) $count));
        }

        $this->newLine();
        $this->info('Duplicate wp_post_id across sites: '.count($report['duplicate_wp_post_ids']));
        foreach (array_slice($report['duplicate_wp_post_ids'], 0, 10) as $dup) {
            $this->line(sprintf(
                '  wp_post_id=%d sites=[%s] articles=[%s]',
                (int) $dup['wp_post_id'],
                implode(',', $dup['site_ids']),
                implode(',', $dup['article_ids']),
            ));
        }

        $limit = max(1, (int) $this->option('limit'));
        $findings = array_slice($report['findings'], 0, $limit);
        if ($findings === []) {
            $this->info('No contamination findings.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['site_id', 'keyword_id', 'keyword', 'article_id', 'article.site_id', 'relation_type'],
            array_map(static fn (array $row): array => [
                $row['site_id'] ?? '',
                $row['keyword_id'] ?? '',
                mb_substr((string) ($row['keyword'] ?? ''), 0, 40),
                $row['article_id'] ?? '',
                $row['article_site_id'] ?? '',
                $row['relation_type'] ?? '',
            ], $findings),
        );

        if (count($report['findings']) > $limit) {
            $this->warn(sprintf('Showing %d / %d findings. Use --json for full report.', $limit, count($report['findings'])));
        }

        return self::SUCCESS;
    }
}
