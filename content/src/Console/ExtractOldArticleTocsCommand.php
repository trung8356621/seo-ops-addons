<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleTocExtractionService;
use Illuminate\Console\Command;
use Throwable;

class ExtractOldArticleTocsCommand extends Command
{
    protected $signature = 'seo-ai:extract-old-tocs
        {--force : Bóc tách lại cả những bài đã có headings}
        {--chunk=100 : Số bài xử lý mỗi chunk}';

    protected $description = 'Bóc tách TOC (H2-H4) hồi tố cho các bài viết chưa có headings';

    public function handle(ArticleTocExtractionService $tocExtraction): int
    {
        $force = (bool) $this->option('force');
        $chunk = max(10, (int) $this->option('chunk'));

        $query = SeoArticle::query()->orderBy('id');
        if (! $force) {
            $query->whereDoesntHave('headings');
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Không có bài viết nào cần bóc tách TOC.');

            return self::SUCCESS;
        }

        $this->info("Bắt đầu bóc tách TOC cho {$total} bài viết...");
        $bar = $this->output->createProgressBar($total);

        $processed = 0;
        $extracted = 0;
        $failed = 0;

        $query->chunkById($chunk, function ($articles) use ($tocExtraction, $bar, &$processed, &$extracted, &$failed): void {
            foreach ($articles as $article) {
                try {
                    $extracted += $tocExtraction->extractForArticle($article);
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Bài #{$article->id} lỗi: {$e->getMessage()}");
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Hoàn tất: {$processed} bài, {$extracted} headings, {$failed} lỗi.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
