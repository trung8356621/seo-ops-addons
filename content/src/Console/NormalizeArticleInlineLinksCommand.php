<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\InlineLinkNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class NormalizeArticleInlineLinksCommand extends Command
{
    protected $signature = 'articles:normalize-inline-links
        {--article= : Chỉ xử lý một article id}
        {--execute : Ghi database (mặc định dry-run)}
        {--dry-run : Giữ tương thích; luôn dry-run trừ khi có --execute}';

    protected $description = 'Merge adjacent duplicate <a> tags in article body (safe DOM normalize). Default: dry-run.';

    public function handle(InlineLinkNormalizer $normalizer): int
    {
        $articleId = (int) ($this->option('article') ?? 0);
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;

        $this->info('articles:normalize-inline-links mode='.($dryRun ? 'dry-run' : 'execute'));

        $query = SeoArticle::query()->orderBy('id');
        if ($articleId > 0) {
            $query->whereKey($articleId);
        }

        $scanned = 0;
        $affected = 0;
        $updated = 0;
        $backupDir = storage_path('app/seo-inline-link-backups/'.now()->format('Ymd_His'));

        if (! $dryRun) {
            File::ensureDirectoryExists($backupDir);
        }

        $query->chunkById(50, function ($articles) use (
            $normalizer,
            $dryRun,
            $backupDir,
            &$scanned,
            &$affected,
            &$updated,
        ): void {
            foreach ($articles as $article) {
                if (! $article instanceof SeoArticle) {
                    continue;
                }

                $scanned++;
                $body = (string) ($article->body ?? '');
                if (trim($body) === '') {
                    continue;
                }

                $report = $normalizer->normalizeWithReport($body);
                if (! $report->changed) {
                    continue;
                }

                $affected++;
                $this->line(sprintf(
                    '#%d anchors=%d dup=%d nested=%d changed=%s',
                    (int) $article->id,
                    $report->before->anchorCount,
                    $report->before->duplicateAdjacentCount,
                    $report->before->nestedAnchorCount,
                    $report->changed ? 'yes' : 'no',
                ));

                $beforeSample = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 180);
                $afterSample = mb_substr(preg_replace('/\s+/u', ' ', $report->html) ?? $report->html, 0, 180);
                $this->line('  before: '.$beforeSample);
                $this->line('  after:  '.$afterSample);

                if ($dryRun) {
                    continue;
                }

                $backupPath = $backupDir.'/article-'.$article->id.'.html';
                File::put($backupPath, $body);

                Log::info('seo.inline_link_normalize', [
                    'article_id' => (int) $article->id,
                    'backup' => $backupPath,
                    'before' => $report->before->toArray(),
                    'after' => $report->after->toArray(),
                    'changes' => $report->changes,
                ]);

                $article->forceFill(['body' => $report->html])->save();
                $updated++;
            }
        });

        $this->newLine();
        $this->info("scanned={$scanned} affected={$affected} updated={$updated}");
        if ($dryRun) {
            $this->warn('Dry-run only. Re-run with --execute to write (backs up body first).');
        } else {
            $this->info('Backups: '.$backupDir);
            $this->warn('WordPress NOT synced. No queue jobs dispatched.');
        }

        return self::SUCCESS;
    }
}
