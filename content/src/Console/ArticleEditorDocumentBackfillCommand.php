<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentSchema;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use Illuminate\Console\Command;

final class ArticleEditorDocumentBackfillCommand extends Command
{
    protected $signature = 'seo:article-editor-document-backfill
        {--article= : Single article id}
        {--site= : Filter by site_id}
        {--limit=100 : Max articles}
        {--dry-run : Do not write DB}
        {--only-pending : Skip migrated/current}
        {--retry-failed : Include failed/manual_review}
        {--force-manual-review : Mark mismatches as manual_review}';

    protected $description = 'Backfill TipTap editor_document JSON from articles.body (Phase 5A)';

    public function handle(ArticleEditorDocumentWriter $writer): int
    {
        if (! $writer->persistenceEnabled()) {
            $this->warn('JSON persistence disabled by config.');

            return self::SUCCESS;
        }

        $query = SeoArticle::query()->orderBy('id');
        if ($articleId = (int) $this->option('article')) {
            $query->whereKey($articleId);
        }
        if ($siteId = (int) $this->option('site')) {
            $query->where('site_id', $siteId);
        }
        if ($this->option('only-pending')) {
            $query->where(function ($q): void {
                $q->whereNull('editor_document')
                    ->orWhereNull('editor_document_status')
                    ->orWhere('editor_document_status', ArticleEditorDocumentSchema::STATUS_PENDING);
            });
        }
        if ($this->option('retry-failed')) {
            $query->orWhereIn('editor_document_status', [
                ArticleEditorDocumentSchema::STATUS_FAILED,
                ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW,
            ]);
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['ok' => 0, 'manual_review' => 0, 'failed' => 0, 'skipped' => 0];

        $query->limit($limit)->chunkById(25, function ($articles) use ($writer, $dryRun, &$stats): void {
            foreach ($articles as $article) {
                if (
                    is_array($article->editor_document)
                    && in_array((string) $article->editor_document_status, [
                        ArticleEditorDocumentSchema::STATUS_CURRENT,
                        ArticleEditorDocumentSchema::STATUS_MIGRATED,
                    ], true)
                    && ! $this->option('retry-failed')
                ) {
                    $stats['skipped']++;
                    continue;
                }

                $result = $writer->lazyMigrateFromBody($article, persist: ! $dryRun);
                if ($result['ok'] ?? false) {
                    $stats['ok']++;
                    $this->line("[ok] article {$article->id}");
                    continue;
                }
                $status = (string) ($result['status'] ?? 'failed');
                if ($status === ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW) {
                    $stats['manual_review']++;
                    $this->warn("[manual_review] article {$article->id}");
                    continue;
                }
                $stats['failed']++;
                $this->error("[failed] article {$article->id} ".($result['code'] ?? ''));
            }
        });

        $this->info('Summary: '.json_encode($stats, JSON_UNESCAPED_UNICODE));
        if ($dryRun) {
            $this->comment('Dry-run: no DB writes.');
        }

        return self::SUCCESS;
    }
}
