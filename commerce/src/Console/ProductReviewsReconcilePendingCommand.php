<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Console;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewReconciliationService;
use Illuminate\Console\Command;

final class ProductReviewsReconcilePendingCommand extends Command
{
    protected $signature = 'product-reviews:reconcile-pending
        {--article= : Article ID}
        {--all : Scan all articles with pending reviews}
        {--dry-run : Report only (default unless --force)}
        {--force : Actually schedule (disable dry-run)}';

    protected $description = 'Reconcile pending product reviews via shared ProductReviewReconciliationService.';

    public function handle(ProductReviewReconciliationService $reconciliation): int
    {
        $dryRun = ! (bool) $this->option('force');
        if ($dryRun) {
            $this->warn('Dry-run (default). Pass --force to schedule for real.');
        }

        $articleIdOpt = $this->option('article');
        $all = (bool) $this->option('all');

        if ($articleIdOpt === null && ! $all) {
            $this->error('Pass --article=ID or --all');

            return self::FAILURE;
        }

        $articleIds = [];
        if ($articleIdOpt !== null) {
            $articleIds = [(int) $articleIdOpt];
        } else {
            $articleIds = ArticleProductReview::query()
                ->whereNull('wp_comment_id')
                ->whereNotIn('status', [
                    ArticleProductReviewStatus::Cancelled->value,
                    ArticleProductReviewStatus::Published->value,
                ])
                ->distinct()
                ->pluck('article_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $totals = [
            'articles_scanned' => 0,
            'legacy_repaired' => 0,
            'queued' => 0,
            'already_scheduled' => 0,
            'already_published' => 0,
            'waiting_for_article' => 0,
            'automation_disabled' => 0,
        ];

        foreach ($articleIds as $articleId) {
            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                $this->line("article #{$articleId}: missing");

                continue;
            }

            $totals['articles_scanned']++;
            $report = $reconciliation->reconcileForArticle($article, null, $dryRun);

            $totals['legacy_repaired'] += (int) ($report['legacy_repaired'] ?? 0);
            $totals['queued'] += (int) ($report['queued'] ?? 0);
            $totals['already_scheduled'] += (int) ($report['already_scheduled'] ?? 0);
            $totals['already_published'] += (int) ($report['already_published'] ?? 0);
            $totals['waiting_for_article'] += (int) ($report['waiting_for_article'] ?? 0);
            if ($report['automation_disabled'] ?? false) {
                $totals['automation_disabled']++;
            }

            $this->line(sprintf(
                'article #%d: queued=%d scheduled=%d published=%d waiting=%d legacy=%d disabled=%s%s',
                $articleId,
                (int) ($report['queued'] ?? 0),
                (int) ($report['already_scheduled'] ?? 0),
                (int) ($report['already_published'] ?? 0),
                (int) ($report['waiting_for_article'] ?? 0),
                (int) ($report['legacy_repaired'] ?? 0),
                ($report['automation_disabled'] ?? false) ? 'yes' : 'no',
                $dryRun ? ' (dry-run)' : '',
            ));
        }

        $this->table(
            ['metric', 'value'],
            collect($totals)->map(static fn ($v, $k): array => [$k, $v])->values()->all(),
        );

        return self::SUCCESS;
    }
}
