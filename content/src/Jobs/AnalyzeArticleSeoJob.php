<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Jobs;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class AnalyzeArticleSeoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public int $articleId,
    ) {}

    public function uniqueId(): string
    {
        return 'analyze-article-seo:'.$this->articleId;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        SeoAnalyzerService $analyzer,
        SeoArticleScoringQueueService $scoringQueue,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        if ((int) ($article->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
            $article = SeoArticle::query()->find($this->articleId);
        }

        if (! $article instanceof SeoArticle || ! $article->countsTowardSeoScore()) {
            return;
        }

        $scoringQueue->markProcessing($article);

        try {
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->seoAnalysisStarted($article);
            $analyzer->analyze($article);
            $fresh = $article->fresh() ?? $article;
            $scoringQueue->markCompleted($fresh);
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->seoAnalysisCompleted($fresh);
        } catch (Throwable $exception) {
            $scoringQueue->markFailed($article, $exception->getMessage());
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->seoAnalysisFailed($article, $exception->getMessage());

            throw $exception;
        }
    }
}
