<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Jobs;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildCoordinator;
use App\Support\RuntimeLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Mode 2 end-to-end job — serial plan→parent→children inside coordinator.
 */
final class RunProductGalleryParentChildJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<int>  $originalSnapshotIds
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly int $articleId,
        public readonly string $configuredMode = 'parent_child',
        public readonly ?string $provider = 'gemini',
        public readonly ?string $model = null,
        public readonly array $originalSnapshotIds = [],
        public readonly array $variables = [],
        public readonly int $requestedImageCount = 6,
        public readonly ?string $executionId = null,
    ) {}

    public function handle(ProductGalleryParentChildCoordinator $coordinator): void
    {
        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            RuntimeLogger::warning('seo.product_gallery.mode2_job_article_missing', [
                'article_id' => $this->articleId,
            ]);

            return;
        }

        $started = microtime(true);

        try {
            $result = $coordinator->run(
                article: $article,
                configuredMode: $this->configuredMode,
                provider: $this->provider,
                model: $this->model,
                originalSnapshotIds: $this->originalSnapshotIds,
                plannerVariables: $this->variables,
                requestedImageCount: $this->requestedImageCount,
                precreatedExecutionId: $this->executionId,
            );

            RuntimeLogger::info('seo.product_gallery.mode2_job_finished', [
                'gallery_execution_id' => (string) ($result['execution_id'] ?? ''),
                'article_id' => $this->articleId,
                'stage' => 'completed',
                'provider' => $this->provider,
                'model' => $this->model,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'fell_back_to_sprite' => (bool) ($result['fell_back_to_sprite'] ?? false),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::warning('seo.product_gallery.mode2_job_failed', [
                'article_id' => $this->articleId,
                'error_code' => 'parent_generation_failed',
                'error' => mb_substr($exception->getMessage(), 0, 240),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            throw $exception;
        }
    }
}
