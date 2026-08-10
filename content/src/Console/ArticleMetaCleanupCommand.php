<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleMetaKeyCatalog;
use App\Support\RuntimeLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safe cleanup of proven orphan article_meta keys.
 * Default is dry-run. Requires --execute to delete.
 */
final class ArticleMetaCleanupCommand extends Command
{
    protected $signature = 'seo:article-meta:cleanup
        {--site= : Filter by site_id}
        {--tenant= : Alias of --site}
        {--article= : Single article id}
        {--key= : Restrict to one cleanup-candidate key}
        {--batch=200 : Delete batch size}
        {--dry-run : Report only (default when --execute omitted)}
        {--execute : Actually delete orphan keys}';

    protected $description = 'Cleanup orphan article_meta keys (dry-run by default)';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');
        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        $siteId = (int) ($this->option('site') ?: $this->option('tenant') ?: 0);
        $articleId = (int) ($this->option('article') ?: 0);
        $onlyKey = trim((string) ($this->option('key') ?: ''));
        $batch = max(25, min(500, (int) $this->option('batch')));

        $candidates = ArticleMetaKeyCatalog::cleanupCandidates();
        if ($onlyKey !== '') {
            if (! in_array($onlyKey, $candidates, true)) {
                $this->error("Key [{$onlyKey}] is not a cleanup candidate. Audit first.");

                return self::FAILURE;
            }
            $candidates = [$onlyKey];
        }

        if ($candidates === []) {
            $this->warn('No cleanup candidates in catalog.');

            return self::SUCCESS;
        }

        $articleIds = null;
        if ($siteId > 0 || $articleId > 0) {
            $q = SeoArticle::query()->select('id');
            if ($articleId > 0) {
                $q->whereKey($articleId);
            }
            if ($siteId > 0) {
                $q->where('site_id', $siteId);
            }
            $articleIds = $q->pluck('id')->all();
            if ($articleIds === []) {
                $this->warn('No articles match scope.');

                return self::SUCCESS;
            }
        }

        $stats = [
            'scanned' => 0,
            'migrated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'by_key' => [],
        ];

        $mode = $dryRun ? 'dry-run' : 'execute';
        $this->info("Mode={$mode} keys=".implode(',', $candidates)." batch={$batch}");

        foreach ($candidates as $key) {
            $def = ArticleMetaKeyCatalog::definition($key);
            if ($def === null || ($def['cleanup'] ?? false) !== true) {
                $stats['skipped']++;
                $stats['by_key'][$key] = ['skipped' => 1, 'reason' => 'not_cleanup'];

                continue;
            }
            if (($def['readers'] ?? []) !== []) {
                $stats['conflicts']++;
                $stats['by_key'][$key] = ['skipped' => 1, 'reason' => 'readers_exist'];
                $this->warn("Skip [{$key}] — catalog still lists readers.");

                continue;
            }

            $query = ArticleMeta::query()->where('meta_key', $key)->orderBy('id');
            if (is_array($articleIds)) {
                $query->whereIn('article_id', $articleIds);
            }

            $keyDeleted = 0;
            $keyScanned = 0;

            $query->chunkById($batch, function ($rows) use (
                $dryRun,
                $key,
                &$stats,
                &$keyDeleted,
                &$keyScanned,
            ): void {
                $ids = [];
                foreach ($rows as $row) {
                    $keyScanned++;
                    $stats['scanned']++;
                    $ids[] = (int) $row->id;
                }

                if ($ids === [] || $dryRun) {
                    return;
                }

                try {
                    DB::connection('omi_seo_ai')->transaction(function () use ($ids, &$keyDeleted, &$stats): void {
                        $deleted = ArticleMeta::query()->whereIn('id', $ids)->delete();
                        $keyDeleted += $deleted;
                        $stats['deleted'] += $deleted;
                    });
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    RuntimeLogger::warning('seo.article_meta.cleanup_failed', [
                        'meta_key' => $key,
                        'batch_size' => count($ids),
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            $stats['by_key'][$key] = [
                'scanned' => $keyScanned,
                'deleted' => $dryRun ? 0 : $keyDeleted,
                'would_delete' => $dryRun ? $keyScanned : $keyDeleted,
            ];

            $this->line(sprintf(
                '[%s] scanned=%d %s=%d',
                $key,
                $keyScanned,
                $dryRun ? 'would_delete' : 'deleted',
                $dryRun ? $keyScanned : $keyDeleted,
            ));
        }

        RuntimeLogger::info('seo.article_meta.cleanup', [
            'mode' => $mode,
            'site_id' => $siteId > 0 ? $siteId : null,
            'article_id' => $articleId > 0 ? $articleId : null,
            'scanned' => $stats['scanned'],
            'deleted' => $stats['deleted'],
            'skipped' => $stats['skipped'],
            'conflicts' => $stats['conflicts'],
            'failed' => $stats['failed'],
            'keys' => array_keys($stats['by_key']),
        ]);

        $this->newLine();
        $this->info(sprintf(
            'scanned=%d migrated=%d deleted=%d skipped=%d conflicts=%d failed=%d',
            $stats['scanned'],
            $stats['migrated'],
            $stats['deleted'],
            $stats['skipped'],
            $stats['conflicts'],
            $stats['failed'],
        ));

        if ($dryRun) {
            $this->warn('Dry-run — no rows deleted. Re-run with --execute to apply.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
