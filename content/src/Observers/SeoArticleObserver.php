<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Observers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleTocExtractionService;
use App\Support\RuntimeLogger;
use Throwable;

final class SeoArticleObserver
{
    /**
     * TOC extract must not run mid-transaction (handlers wrap article saves).
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly ArticleTocExtractionService $tocExtraction,
    ) {}

    public function saved(SeoArticle $article): void
    {
        if (! $article->wasRecentlyCreated && ! $article->wasChanged('body')) {
            return;
        }

        try {
            $this->tocExtraction->extractForArticle($article);
        } catch (Throwable $e) {
            // TOC is best-effort — never fail the article save path.
            RuntimeLogger::warning('SeoArticleObserver: TOC extraction failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
