<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Jobs\RunProductGalleryParentChildJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;

/**
 * Validate + dispatch Mode 2 job (coordinator owns append-only execution rows).
 */
final class ProductGalleryParentChildDispatchService
{
    public function __construct(
        private readonly ImageProviderCapabilityResolver $capabilities,
        private readonly ProductGalleryModeOrchestrator $orchestrator,
    ) {}

    /**
     * @param  list<int>  $originalSnapshotIds
     * @param  array<string, mixed>  $variables
     * @return array{
     *     ok: bool,
     *     route: string,
     *     execution_id?: string,
     *     message?: string,
     *     error_code?: string,
     *     mode_resolution?: array<string, mixed>,
     *     existing?: bool,
     *     job_dispatched?: bool
     * }
     */
    public function start(
        SeoArticle $article,
        string $requestedMode,
        array $originalSnapshotIds,
        array $variables = [],
        ?string $provider = 'gemini',
        ?string $model = null,
        int $requestedImageCount = 6,
        bool $force = false,
    ): array {
        $articleId = (int) $article->id;
        if (! $force && ! ProductGalleryParentChildFeature::allowsArticle($articleId)) {
            return [
                'ok' => false,
                'route' => 'sprite',
                'error_code' => 'feature_disabled',
                'message' => 'Mode 2 Parent/Child is disabled for this article.',
            ];
        }

        $caps = $this->capabilities->resolve($provider, $model);
        $decision = $this->orchestrator->decide($requestedMode, $provider, $model);
        $resolution = $decision['resolution'];

        if ($decision['route'] !== 'parent_child') {
            return [
                'ok' => false,
                'route' => $decision['route'],
                'error_code' => $resolution->reason !== '' ? $resolution->reason : 'resolved_to_sprite',
                'message' => 'Resolved mode is not parent_child.',
                'mode_resolution' => $resolution->toArray(),
            ];
        }

        if (! $caps->allowsParentChild()) {
            return [
                'ok' => false,
                'route' => 'sprite',
                'error_code' => 'reference_transport_unsupported',
                'message' => 'Không có model ảnh hỗ trợ Parent/Child trong cấu hình hiện tại.',
                'mode_resolution' => $resolution->toArray(),
            ];
        }

        $active = SeoProductGalleryExecution::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                'pending',
                'running',
                'planning',
                'generating_parent',
                'generating_children',
                'selecting_gallery',
            ])
            ->orderByDesc('id')
            ->first();

        if ($active instanceof SeoProductGalleryExecution) {
            return [
                'ok' => true,
                'route' => 'parent_child',
                'execution_id' => (string) $active->execution_id,
                'existing' => true,
                'job_dispatched' => false,
                'message' => 'Active Mode 2 execution already running.',
                'mode_resolution' => $resolution->toArray(),
            ];
        }

        $lockKey = 'seo:pgpc:dispatch:'.$articleId;
        $gotLock = cache()->lock($lockKey, 30)->get();
        if (! $gotLock) {
            return [
                'ok' => false,
                'route' => 'parent_child',
                'error_code' => 'dispatch_in_progress',
                'message' => 'Another Mode 2 dispatch is in progress.',
            ];
        }

        try {
            // Re-check under lock.
            $active = SeoProductGalleryExecution::query()
                ->where('article_id', $articleId)
                ->whereIn('status', ['pending', 'running', 'planning', 'generating_parent', 'generating_children', 'selecting_gallery'])
                ->orderByDesc('id')
                ->first();
            if ($active instanceof SeoProductGalleryExecution) {
                return [
                    'ok' => true,
                    'route' => 'parent_child',
                    'execution_id' => (string) $active->execution_id,
                    'existing' => true,
                    'job_dispatched' => false,
                    'mode_resolution' => $resolution->toArray(),
                ];
            }

            $executionId = 'pgpc_'.bin2hex(random_bytes(8));
            SeoProductGalleryExecution::query()->create([
                'execution_id' => $executionId,
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'generation_mode' => ProductGalleryGenerationMode::ParentChild->value,
                'status' => 'pending',
                'provider_snapshot' => $caps->toArray(),
                'original_media_snapshot_ids' => $originalSnapshotIds,
                'started_at' => now(),
            ]);

            RunProductGalleryParentChildJob::dispatch(
                articleId: $articleId,
                configuredMode: $requestedMode,
                provider: $provider,
                model: $model,
                originalSnapshotIds: $originalSnapshotIds,
                variables: $variables,
                requestedImageCount: $requestedImageCount,
                executionId: $executionId,
            );

            return [
                'ok' => true,
                'route' => 'parent_child',
                'execution_id' => $executionId,
                'job_dispatched' => true,
                'existing' => false,
                'mode_resolution' => $resolution->toArray(),
                'message' => 'Mode 2 job dispatched.',
            ];
        } finally {
            try {
                cache()->lock($lockKey, 30)->forceRelease();
            } catch (\Throwable) {
            }
        }
    }
}
