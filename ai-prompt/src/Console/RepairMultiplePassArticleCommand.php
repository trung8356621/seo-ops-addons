<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleRepairService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;

/**
 * php artisan seo:repair-multiple-pass-article {articleId} --parent-result=1486 [--dry-run]
 */
final class RepairMultiplePassArticleCommand extends Command
{
    protected $signature = 'seo:repair-multiple-pass-article
        {articleId : Seo article id}
        {--parent-result= : MULTIPLE_PASS parent PromptResult id}
        {--dry-run : Report only, do not persist}';

    protected $description = 'Repair MULTIPLE_PASS article headings from selected successful section outputs (no AI re-call)';

    public function handle(SectionedFreeArticleRepairService $repairService): int
    {
        $articleId = (int) $this->argument('articleId');
        $parentId = (int) $this->option('parent-result');
        $dryRun = (bool) $this->option('dry-run');

        if ($articleId <= 0 || $parentId <= 0) {
            $this->error('Require articleId and --parent-result=');

            return self::FAILURE;
        }

        $rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
        if ($rec) {
            app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
        }

        $report = $dryRun
            ? $repairService->dryRun($articleId, $parentId)
            : $repairService->repair($articleId, $parentId);

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($report['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
