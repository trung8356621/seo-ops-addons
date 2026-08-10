<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ArticleProductReviewStoreService;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ProductReviewsMigrateLegacyMetaCommand extends Command
{
    protected $signature = 'product-reviews:migrate-legacy-meta
                            {--dry-run : Report only (default unless --execute)}
                            {--execute : Write article_product_reviews rows}
                            {--article= : Limit to one article id}';

    protected $description = 'Migrate article_meta virtual_comments into article_product_reviews (never deletes legacy meta).';

    public function handle(
        VirtualCommentService $virtualComments,
        ArticleProductReviewStoreService $store,
    ): int {
        $dryRun = ! (bool) $this->option('execute');
        if ($this->option('dry-run')) {
            $dryRun = true;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
            $this->error('Table article_meta không tồn tại trên connection omi_seo_ai.');

            return self::FAILURE;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('article_product_reviews')) {
            $this->error('Table article_product_reviews chưa migrate. Chạy migration SEO trước.');

            return self::FAILURE;
        }

        $connectionId = (int) (SeoConnectionContext::current()?->id ?? 0);
        if ($connectionId <= 0 && ! $dryRun) {
            $this->error('SeoConnectionContext missing — bootstrap SEO connection first.');

            return self::FAILURE;
        }

        $query = ArticleMeta::query()
            ->where('meta_key', VirtualCommentService::ARTICLE_META_KEY);

        if ($this->option('article')) {
            $query->where('article_id', (int) $this->option('article'));
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($query->cursor() as $meta) {
            /** @var ArticleMeta $meta */
            $article = SeoArticle::query()->find((int) $meta->article_id);
            if (! $article instanceof SeoArticle) {
                $skipped++;
                continue;
            }

            $existing = ArticleProductReview::query()->where('article_id', (int) $article->id)->count();
            if ($existing > 0) {
                $skipped++;
                $this->line("article #{$article->id}: already has {$existing} reviews — skip");
                continue;
            }

            $items = $virtualComments->getFromArticle($article);
            if ($items === []) {
                $skipped++;
                continue;
            }

            $this->info("article #{$article->id}: ".count($items).' legacy items'.($dryRun ? ' (dry-run)' : ''));

            if ($dryRun) {
                $migrated += count($items);
                continue;
            }

            $result = $store->storeItems($article, $items, 'legacy_meta_migrate');
            $migrated += (int) ($result['created_count'] ?? 0);
        }

        $this->table(['metric', 'count'], [
            ['migrated_or_would_migrate', $migrated],
            ['skipped', $skipped],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
