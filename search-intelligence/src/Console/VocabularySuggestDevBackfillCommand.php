<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularySuggestDevBackfillService;

/**
 * Temporary dev backfill: article_meta.seo_article_keywords → Vocabulary Suggest.
 * Delete when Keywords UI no longer needs seeded local data.
 *
 * php artisan seo:vocabulary:backfill-suggest --site=7 --articles=5 --limit=20 --dry-run
 */
final class VocabularySuggestDevBackfillCommand extends Command
{
    protected $signature = 'seo:vocabulary:backfill-suggest
        {--site= : Site ID (required)}
        {--articles=5 : Max articles with seo_article_keywords}
        {--limit=20 : Max Suggest candidates total}
        {--per-article=4 : Max candidates per article}
        {--dry-run : Preview only; no keyword writes}';

    protected $description = 'Dev-only: backfill ~10–20 Vocabulary Suggest from recent seo_article_keywords (no AI).';

    public function handle(VocabularySuggestDevBackfillService $service): int
    {
        $siteId = (int) $this->option('site');
        if ($siteId <= 0) {
            $this->error('--site is required.');

            return self::FAILURE;
        }

        $articleLimit = max(1, (int) $this->option('articles'));
        $suggestLimit = max(1, min(50, (int) $this->option('limit')));
        $perArticle = max(1, min(10, (int) $this->option('per-article')));
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->run($siteId, $articleLimit, $suggestLimit, $perArticle, $dryRun);

        $this->info(($dryRun ? '[dry-run] ' : '').'site_id='.$siteId);
        $this->line('Articles used: '.count($result['articles']));
        foreach ($result['articles'] as $row) {
            $this->line(sprintf(
                '  - #%d (%d groups) %s',
                $row['article_id'],
                $row['group_count'],
                $row['title'],
            ));
        }

        $this->newLine();
        $this->line('Candidates selected: '.$result['selected']);
        $this->line('New Suggest rows: '.$result['would_ingest_new']);
        $this->line('Suggest re-stamp → ai_generated: '.(int) ($result['would_restamp_suggest'] ?? $result['would_dedupe']));

        $this->newLine();
        $this->table(
            ['article_id', 'group', 'phrase', 'status'],
            array_map(static fn (array $c): array => [
                $c['article_id'],
                $c['group'],
                $c['phrase'],
                $c['status'] ?? ($c['existing'] ? 'existing' : 'new'),
            ], $result['candidates']),
        );

        if (! $dryRun && is_array($result['feedback'])) {
            $fb = $result['feedback'];
            $this->newLine();
            $this->info(sprintf(
                'Ingest feedback: discovered=%d ingested=%d duplicates=%d filtered=%d classified=%d',
                (int) ($fb['discovered'] ?? 0),
                (int) ($fb['ingested'] ?? 0),
                (int) ($fb['duplicates'] ?? 0),
                (int) ($fb['filtered'] ?? 0),
                (int) ($fb['classified'] ?? 0),
            ));
            if (($fb['errors'] ?? []) !== []) {
                $this->warn('errors: '.implode(' | ', array_map('strval', $fb['errors'])));
            }
        }

        return self::SUCCESS;
    }
}
