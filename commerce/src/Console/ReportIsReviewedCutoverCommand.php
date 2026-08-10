<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Console;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Omnichannel\Addons\Content\Support\ArticleReviewCutoverRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch C dry-run report before dropping articles.is_reviewed.
 */
final class ReportIsReviewedCutoverCommand extends Command
{
    protected $signature = 'seo:articles:report-is-reviewed-cutover
        {--chunk=500 : Chunk size}';

    protected $description = 'Dry-run report for is_reviewed cutover (no writes).';

    public function handle(): int
    {
        $connection = SeoContentAiServiceProvider::DB_CONNECTION;
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('articles')) {
            $this->warn('articles table missing — nothing to report.');

            return self::SUCCESS;
        }

        if (! $schema->hasColumn('articles', 'is_reviewed')) {
            $this->info('articles.is_reviewed already dropped — cutover complete.');

            return self::SUCCESS;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $stats = ArticleReviewCutoverRules::emptyStats();

        SeoArticle::query()
            ->orderBy('id')
            ->select(['id', 'review_status', 'is_reviewed', 'reviewed_at'])
            ->chunkById($chunk, function ($articles) use (&$stats): void {
                foreach ($articles as $article) {
                    if (! $article instanceof SeoArticle) {
                        continue;
                    }

                    $stats['scanned']++;
                    $isReviewed = (bool) ($article->is_reviewed ?? false);
                    $decision = ArticleReviewCutoverRules::decide(
                        is_string($article->review_status ?? null) ? (string) $article->review_status : null,
                        $isReviewed,
                    );

                    $rule = $decision['rule'];
                    if (isset($stats[$rule])) {
                        $stats[$rule]++;
                    } else {
                        $stats['preserve_other']++;
                    }

                    if ($decision['action'] !== 'preserve') {
                        $stats['updated']++;
                    }
                }
            });

        $this->info('is_reviewed cutover dry-run (no writes)');
        $this->table(array_keys($stats), [array_values($stats)]);

        return self::SUCCESS;
    }
}
