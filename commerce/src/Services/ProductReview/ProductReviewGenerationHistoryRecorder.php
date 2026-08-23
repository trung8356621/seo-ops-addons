<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultCommentPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Throwable;

/**
 * Persist template / AI product-review generations into PromptResult + article link
 * so they appear on Article AI History.
 */
final class ProductReviewGenerationHistoryRecorder
{
    public function __construct(
        private readonly PromptResultLinkService $links,
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @param  list<int>  $reviewIds
     */
    public function recordTemplateBatch(
        SeoArticle $article,
        array $reviewIds,
        string $generationBatchId,
        int $createdCount,
        int $targetCount,
    ): void {
        if ($createdCount <= 0 || $reviewIds === []) {
            return;
        }

        try {
            $promptId = $this->resolveCommentPromptId();
            if ($promptId <= 0) {
                return;
            }

            $lines = ArticleProductReview::query()
                ->whereIn('id', $reviewIds)
                ->orderBy('id')
                ->get(['author_name', 'author_email', 'content', 'rating'])
                ->map(static function (ArticleProductReview $row): string {
                    $email = trim((string) ($row->author_email ?? ''));
                    if ($email === '') {
                        $email = 'noreply@example.com';
                    }

                    return sprintf(
                        '%s | %s | %s',
                        trim((string) $row->author_name),
                        $email,
                        trim((string) $row->content),
                    );
                })
                ->filter(static fn (string $line): bool => $line !== '')
                ->values()
                ->all();

            $output = implode("\n", $lines);
            $now = now();

            $result = PromptResult::query()->create([
                'prompt_id' => $promptId,
                'user_id' => (int) (auth()->id() ?? 0),
                'site_id' => (int) ($article->site_id ?? 0),
                'status' => 'completed',
                'input_snapshot' => [
                    'article_id' => (int) $article->id,
                    'variables' => [
                        'article_id' => (int) $article->id,
                        'hook_key' => DefaultCommentPromptInstaller::HOOK_KEY,
                        'post_title' => (string) ($article->title ?? ''),
                        'comment_count' => $createdCount,
                        'target_count' => $targetCount,
                    ],
                    'hook_key' => DefaultCommentPromptInstaller::HOOK_KEY,
                    'generation_source' => 'product_review_template',
                    'generation_batch_id' => $generationBatchId !== '' ? $generationBatchId : null,
                    'compiled_prompt' => sprintf(
                        'Template product reviews (%d/%d) for article #%d',
                        $createdCount,
                        $targetCount,
                        (int) $article->id,
                    ),
                ],
                'output_text' => $output,
                'started_at' => $now,
                'finished_at' => $now,
            ]);

            $this->links->linkPromptResult(
                promptResultId: (int) $result->id,
                articleId: (int) $article->id,
                source: 'product_review_template',
                workflowStepTitle: 'Review',
                meta: [
                    'status' => 'completed',
                    'type' => 'review',
                    'prompt_name' => 'Review',
                    'generation_batch_id' => $generationBatchId,
                    'created_count' => $createdCount,
                ],
            );
        } catch (Throwable) {
            // History is best-effort — never fail create because of PromptResult write.
        }
    }

    private function resolveCommentPromptId(): int
    {
        $bindings = $this->settings->getPromptHookBindings();
        $bound = (int) ($bindings[DefaultCommentPromptInstaller::HOOK_KEY] ?? 0);
        if ($bound > 0 && SeoPrompt::query()->whereKey($bound)->exists()) {
            return $bound;
        }

        $fallback = SeoPrompt::query()
            ->where('hook_key', DefaultCommentPromptInstaller::HOOK_KEY)
            ->orderBy('id')
            ->value('id');

        return $fallback !== null ? (int) $fallback : 0;
    }
}
