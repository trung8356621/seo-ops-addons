<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageStatus;
use Illuminate\Console\Command;

/**
 * Idempotent backfill of articles.featured_* projection from stored Laravel sources.
 * No WordPress HTTP per row.
 */
final class BackfillArticleFeaturedImageProjectionCommand extends Command
{
    protected $signature = 'seo:article-featured-image-projection-backfill
        {--article= : Single article id}
        {--site= : Filter by site_id}
        {--limit=500 : Max articles}
        {--only-unknown : Only rows with null/unknown status}
        {--dry-run : Do not write DB}';

    protected $description = 'Backfill canonical featured_thumb_url / status projection for Article List';

    public function handle(ArticleFeaturedImageProjection $projection): int
    {
        $query = SeoArticle::query()->orderBy('id');
        if ($articleId = (int) $this->option('article')) {
            $query->whereKey($articleId);
        }
        if ($siteId = (int) $this->option('site')) {
            $query->where('site_id', $siteId);
        }
        if ($this->option('only-unknown')) {
            $query->where(function ($q): void {
                $q->whereNull('featured_image_status')
                    ->orWhere('featured_image_status', ArticleFeaturedImageStatus::UNKNOWN);
            });
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $stats = [
            'scanned' => 0,
            'repaired' => 0,
            'already_valid' => 0,
            'absent' => 0,
            'unknown' => 0,
            'available' => 0,
            'conflicts' => 0,
            'by_source' => [],
        ];

        $query->limit($limit)->chunkById(50, function ($articles) use ($projection, $dryRun, &$stats): void {
            foreach ($articles as $article) {
                if (! $article instanceof SeoArticle) {
                    continue;
                }
                $stats['scanned']++;
                $result = $projection->rebuildAndPersist($article, persist: ! $dryRun);
                $status = $result['status'];
                $source = $result['source'];

                $stats['by_source'][$source] = ($stats['by_source'][$source] ?? 0) + 1;
                if ($result['conflict']) {
                    $stats['conflicts']++;
                }

                match ($status) {
                    ArticleFeaturedImageStatus::AVAILABLE => $stats['available']++,
                    ArticleFeaturedImageStatus::ABSENT => $stats['absent']++,
                    default => $stats['unknown']++,
                };

                if ($result['changed']) {
                    $stats['repaired']++;
                    $this->line(sprintf(
                        '[%s] article %d source=%s%s',
                        $dryRun ? 'dry' : 'ok',
                        (int) $article->id,
                        $source,
                        $result['conflict'] ? ' CONFLICT' : '',
                    ));
                } else {
                    $stats['already_valid']++;
                }
            }
        });

        $this->info('Summary: '.json_encode($stats, JSON_UNESCAPED_UNICODE));
        if ($dryRun) {
            $this->comment('Dry-run: no DB writes.');
        }

        return self::SUCCESS;
    }
}
