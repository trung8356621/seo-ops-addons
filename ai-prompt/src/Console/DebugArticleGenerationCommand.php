<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeDebugReportService;

/**
 * php artisan seo:debug-article-generation {articleId}
 */
final class DebugArticleGenerationCommand extends Command
{
    protected $signature = 'seo:debug-article-generation
        {articleId : Seo article id}
        {--json : Output JSON}';

    protected $description = 'Debug sectioned_free article generation observability (parent/child PromptResults)';

    public function handle(SectionedFreeDebugReportService $reportService): int
    {
        $articleId = (int) $this->argument('articleId');
        if ($articleId <= 0) {
            $this->error('Invalid articleId');

            return self::FAILURE;
        }

        $report = $reportService->reportForArticle($articleId);
        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->line($reportService->formatText($report));

        return self::SUCCESS;
    }
}
