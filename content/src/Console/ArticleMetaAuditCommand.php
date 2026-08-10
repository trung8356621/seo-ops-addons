<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleMetaKeyCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inventory article_meta keys with catalog classification + DB counts.
 * Never prints article body or sensitive payloads.
 */
final class ArticleMetaAuditCommand extends Command
{
    protected $signature = 'seo:article-meta:audit
        {--site= : Filter by site_id}
        {--tenant= : Alias of --site}
        {--article= : Single article id}
        {--key= : Only one meta_key}
        {--batch=500 : Chunk size for scanning}';

    protected $description = 'Audit article_meta keys: counts, classification, proposed action';

    public function handle(): int
    {
        $siteId = (int) ($this->option('site') ?: $this->option('tenant') ?: 0);
        $articleId = (int) ($this->option('article') ?: 0);
        $onlyKey = trim((string) ($this->option('key') ?: ''));
        $batch = max(50, (int) $this->option('batch'));

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

        $aggQuery = ArticleMeta::query()
            ->select([
                'meta_key',
                DB::raw('COUNT(*) as record_count'),
                DB::raw('COUNT(DISTINCT article_id) as article_count'),
                DB::raw('MIN(updated_at) as oldest'),
                DB::raw('MAX(updated_at) as newest'),
            ])
            ->groupBy('meta_key')
            ->orderBy('meta_key');

        if (is_array($articleIds)) {
            $aggQuery->whereIn('article_id', $articleIds);
        }
        if ($onlyKey !== '') {
            $aggQuery->where('meta_key', $onlyKey);
        }

        $rows = $aggQuery->get();
        $known = ArticleMetaKeyCatalog::definitions();
        $seen = [];

        $this->table(
            ['key', 'records', 'articles', 'oldest', 'newest', 'class', 'readers', 'writers', 'action'],
            $rows->map(function ($row) use ($known, &$seen): array {
                $key = (string) $row->meta_key;
                $seen[$key] = true;
                $def = $known[$key] ?? null;
                $class = $def['class'] ?? 'unknown';
                $action = $this->proposeAction($class, $def);

                return [
                    $key,
                    (string) $row->record_count,
                    (string) $row->article_count,
                    (string) ($row->oldest ?? '—'),
                    (string) ($row->newest ?? '—'),
                    $class,
                    (string) count($def['readers'] ?? []),
                    (string) count($def['writers'] ?? []),
                    $action,
                ];
            })->all(),
        );

        $missingInDb = [];
        foreach ($known as $key => $def) {
            if ($onlyKey !== '' && $key !== $onlyKey) {
                continue;
            }
            if (! isset($seen[$key])) {
                $missingInDb[] = [$key, $def['class'], $this->proposeAction($def['class'], $def)];
            }
        }

        if ($missingInDb !== []) {
            $this->newLine();
            $this->info('Catalog keys with zero DB rows in scope:');
            $this->table(['key', 'class', 'action'], $missingInDb);
        }

        $unknown = $rows->filter(static fn ($row): bool => ! isset($known[(string) $row->meta_key]));
        if ($unknown->isNotEmpty()) {
            $this->newLine();
            $this->warn('Keys in DB not in catalog (investigate before delete):');
            foreach ($unknown as $row) {
                $this->line(sprintf(
                    '  %s — records=%d articles=%d',
                    $row->meta_key,
                    (int) $row->record_count,
                    (int) $row->article_count,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf('batch=%d (info only for audit aggregation)', $batch));
        $this->info('Audit complete. Cleanup: php artisan seo:article-meta:cleanup --dry-run');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $def
     */
    private function proposeAction(string $class, ?array $def): string
    {
        return match ($class) {
            ArticleMetaKeyCatalog::CLASS_CANONICAL => 'keep',
            ArticleMetaKeyCatalog::CLASS_COMPATIBILITY => 'keep_compat',
            ArticleMetaKeyCatalog::CLASS_CACHE => 'keep_rebuildable',
            ArticleMetaKeyCatalog::CLASS_RUNTIME => 'keep_runtime',
            ArticleMetaKeyCatalog::CLASS_LEGACY => 'migrate_then_delete',
            ArticleMetaKeyCatalog::CLASS_DEBUG => 'ttl_or_command',
            ArticleMetaKeyCatalog::CLASS_ORPHAN => (($def['cleanup'] ?? false) ? 'delete_candidate' : 'keep_reader_exists'),
            default => 'investigate',
        };
    }
}
