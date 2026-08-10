<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Seo\Contracts\ProductGalleryParentChildAiPort;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryChildAttempt;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution;
use Omnichannel\Addons\Media\Support\ProductGallery\ImageProviderCapabilities;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryPlan;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySelectionResult;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;
use Throwable;

/**
 * Mode 2 serial Parent/Child coordinator (scaffold).
 * Child jobs run one-by-one; failures do not delete parent/originals.
 */
final class ProductGalleryParentChildCoordinator
{
    public function __construct(
        private readonly ProductGalleryParentChildAiPort $ai,
        private readonly ProductGalleryPlanParser $planParser,
        private readonly ProductGalleryReferenceChildValidator $childValidator,
        private readonly ProductGallerySelectionService $selection,
        private readonly ArticleMediaLocalServiceBridge $album,
        private readonly ImageProviderCapabilityResolver $capabilities,
        private readonly ProductGalleryGenerationModeResolver $modeResolver,
    ) {}

    /**
     * @param  list<int>  $originalSnapshotIds
     * @param  array<string, mixed>  $plannerVariables
     * @return array{
     *     selection: ProductGallerySelectionResult,
     *     execution_id: string,
     *     mode_resolution: array<string, mixed>,
     *     progress: list<string>,
     *     fell_back_to_sprite: bool
     * }
     */
    public function run(
        SeoArticle $article,
        string $configuredMode,
        ?string $provider,
        ?string $model,
        array $originalSnapshotIds,
        array $plannerVariables = [],
        int $requestedImageCount = 6,
        ?ProductGalleryParentChildAiPort $aiOverride = null,
        ?string $precreatedExecutionId = null,
    ): array {
        $ai = $aiOverride ?? $this->ai;
        $caps = $this->capabilities->resolve($provider, $model);
        $modeResolution = $this->modeResolver->resolve($configuredMode, $caps);
        $progress = [];

        if ($modeResolution->resolved !== ProductGalleryGenerationMode::ParentChild) {
            return [
                'selection' => $this->selection->select(
                    usableChildIds: [],
                    rejectedChildIds: [],
                    originalSnapshotIds: $originalSnapshotIds,
                    mode: ProductGalleryGenerationMode::Sprite,
                    historyExtra: ['mode_resolution' => $modeResolution->toArray()],
                ),
                'execution_id' => '',
                'mode_resolution' => $modeResolution->toArray(),
                'progress' => ['resolved_to_sprite'],
                'fell_back_to_sprite' => true,
            ];
        }

        $precreated = trim((string) $precreatedExecutionId);
        $execution = null;
        if ($precreated !== '') {
            $execution = SeoProductGalleryExecution::query()
                ->where('execution_id', $precreated)
                ->where('article_id', (int) $article->id)
                ->first();
        }

        if ($execution instanceof SeoProductGalleryExecution) {
            $executionId = (string) $execution->execution_id;
            $execution->update([
                'status' => 'running',
                'provider_snapshot' => $caps->toArray(),
                'original_media_snapshot_ids' => $originalSnapshotIds,
                'started_at' => $execution->started_at ?? now(),
            ]);
        } else {
            $executionId = $precreated !== '' ? $precreated : 'pgpc_'.bin2hex(random_bytes(8));
            $execution = $this->createExecution($article, $executionId, $caps, $originalSnapshotIds);
        }

        $resolvedModel = trim((string) $model);
        if ($resolvedModel !== '') {
            $plannerVariables['model'] = $resolvedModel;
            $plannerVariables['image_model'] = $resolvedModel;
        }
        if (trim((string) $provider) !== '') {
            $plannerVariables['provider'] = trim((string) $provider);
        }

        $progress[] = 'planning';

        try {
            $planRaw = $ai->runPlanner($article, array_merge($plannerVariables, [
                'requested_image_count' => $requestedImageCount,
            ]));
            $parsed = $this->planParser->parse($planRaw, $requestedImageCount);
            if (! $parsed['ok'] || ! $parsed['plan'] instanceof ProductGalleryPlan) {
                $execution->update([
                    'status' => 'failed',
                    'failure_reason' => 'planner_invalid:'.implode(',', $parsed['errors']),
                    'completed_at' => now(),
                ]);

                return $this->finishWithFallback(
                    $article,
                    $execution,
                    $originalSnapshotIds,
                    $modeResolution,
                    array_merge($progress, ['planner_failed']),
                    'planner_invalid',
                );
            }

            /** @var ProductGalleryPlan $plan */
            $plan = $parsed['plan'];
            $execution->update(['planner_snapshot' => $plan->toArray()]);

            $progress[] = 'generating_parent';
            $parent = $ai->generateParent($article, $plannerVariables);
            if (! $parent instanceof SeoMedia) {
                $execution->update([
                    'status' => 'failed',
                    'failure_reason' => 'parent_generate_failed',
                    'completed_at' => now(),
                ]);

                return $this->finishWithFallback(
                    $article,
                    $execution,
                    $originalSnapshotIds,
                    $modeResolution,
                    array_merge($progress, ['parent_failed']),
                    'parent_generate_failed',
                );
            }

            $this->tagParent($parent, $executionId);
            $execution->update(['parent_media_id' => (int) $parent->id]);

            $context = $this->buildContext(
                $article,
                $executionId,
                $originalSnapshotIds,
                $parent,
                $caps,
                $plannerVariables,
            );
            $execution->update(['global_context_snapshot' => $context->toArray()]);

            $usable = [];
            $rejected = [];
            $acceptedMedia = [];
            $retryCount = $this->childRetryCount();

            foreach ($plan->shots as $shot) {
                $progress[] = sprintf('generating_child_%d/%d', $shot->slot, count($plan->shots));
                $childMedia = $this->generateChildSerial(
                    $ai,
                    $article,
                    $execution,
                    $parent,
                    $shot,
                    $context,
                    $plannerVariables,
                    $retryCount,
                );

                if (! $childMedia instanceof SeoMedia) {
                    $rejected[] = 0;

                    continue;
                }

                $check = $this->childValidator->validate($childMedia, $acceptedMedia);
                if (! $check['ok']) {
                    $rejected[] = (int) $childMedia->id;

                    continue;
                }

                $this->tagChild($childMedia, $parent, $executionId, $shot);
                $usable[] = (int) $childMedia->id;
                $acceptedMedia[] = $childMedia;
            }

            $progress[] = 'selecting_gallery';
            $selection = $this->selection->select(
                usableChildIds: $usable,
                rejectedChildIds: array_values(array_filter($rejected)),
                originalSnapshotIds: $originalSnapshotIds,
                mode: ProductGalleryGenerationMode::ParentChild,
                galleryExecutionId: $executionId,
                historyExtra: [
                    'mode_resolution' => $modeResolution->toArray(),
                    'parent_media_id' => (int) $parent->id,
                ],
            );

            if ($selection->galleryReady && $selection->selectedMediaIds !== []) {
                $this->album->replaceAlbum($article, $selection->selectedMediaIds);
            }

            $this->mirrorSelection($article, $selection, $executionId);
            $execution->update([
                'status' => $selection->galleryReady ? 'completed' : 'failed',
                'selection_snapshot' => $selection->toArray(),
                'completed_at' => now(),
                'failure_reason' => $selection->galleryReady ? null : $selection->reason,
            ]);

            $progress[] = $selection->gallerySource->value === 'original_images'
                ? 'fallback_original'
                : 'completed';

            return [
                'selection' => $selection,
                'execution_id' => $executionId,
                'mode_resolution' => $modeResolution->toArray(),
                'progress' => $progress,
                'fell_back_to_sprite' => false,
            ];
        } catch (Throwable $exception) {
            $execution->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);

            return $this->finishWithFallback(
                $article,
                $execution,
                $originalSnapshotIds,
                $modeResolution,
                array_merge($progress, ['exception']),
                $exception->getMessage(),
            );
        }
    }

    /**
     * Retry one child — reuse planner/parent/context/shot; new attempt row.
     *
     * @param  array<string, mixed>  $variables
     */
    public function retryChild(
        SeoProductGalleryExecution $execution,
        ProductGalleryShotDefinition $shot,
        SeoArticle $article,
        array $variables = [],
        ?ProductGalleryParentChildAiPort $aiOverride = null,
    ): SeoProductGalleryChildAttempt {
        $ai = $aiOverride ?? $this->ai;
        $parentId = (int) ($execution->parent_media_id ?? 0);
        $parent = SeoMedia::query()->find($parentId);
        if (! $parent instanceof SeoMedia) {
            throw new \InvalidArgumentException('Parent media missing for child retry.');
        }

        $contextData = is_array($execution->global_context_snapshot) ? $execution->global_context_snapshot : [];
        $context = ProductGalleryGlobalContext::fromArray($contextData);
        $lastAttempt = (int) SeoProductGalleryChildAttempt::query()
            ->where('parent_execution_id', (int) $execution->id)
            ->where('slot_index', $shot->slot)
            ->max('attempt');
        $attemptNo = $lastAttempt + 1;

        return $this->runChildAttempt(
            $ai,
            $article,
            $execution,
            $parent,
            $shot,
            $context,
            $variables,
            $attemptNo,
        );
    }

    /**
     * @param  list<int>  $originalSnapshotIds
     * @param  array<string, mixed>  $plannerVariables
     */
    private function buildContext(
        SeoArticle $article,
        string $executionId,
        array $originalSnapshotIds,
        SeoMedia $parent,
        ImageProviderCapabilities $caps,
        array $plannerVariables,
    ): ProductGalleryGlobalContext {
        return ProductGalleryGlobalContext::fromArray([
            'execution_id' => $executionId,
            'article_id' => (int) $article->id,
            'product_identity' => (string) ($plannerVariables['product_identity'] ?? $article->title ?? ''),
            'title' => (string) ($plannerVariables['title'] ?? $article->title ?? ''),
            'brand' => (string) ($plannerVariables['brand'] ?? ''),
            'category' => (string) ($plannerVariables['category'] ?? ''),
            'primary_color' => (string) ($plannerVariables['primary_color'] ?? ''),
            'secondary_color' => (string) ($plannerVariables['secondary_color'] ?? ''),
            'material' => (string) ($plannerVariables['material'] ?? ''),
            'shape' => (string) ($plannerVariables['shape'] ?? ''),
            'logo_position' => (string) ($plannerVariables['logo_position'] ?? ''),
            'hardware' => (string) ($plannerVariables['hardware'] ?? ''),
            'strap_count' => isset($plannerVariables['strap_count']) ? (int) $plannerVariables['strap_count'] : null,
            'pocket_count' => isset($plannerVariables['pocket_count']) ? (int) $plannerVariables['pocket_count'] : null,
            'distinctive_features' => is_array($plannerVariables['distinctive_features'] ?? null)
                ? $plannerVariables['distinctive_features']
                : [],
            'negative_constraints' => is_array($plannerVariables['negative_constraints'] ?? null)
                ? $plannerVariables['negative_constraints']
                : array_filter([(string) ($plannerVariables['negative_constraints'] ?? '')]),
            'original_media_ids' => $originalSnapshotIds,
            'parent_media_id' => (int) $parent->id,
            'provider' => $caps->provider,
            'model' => $caps->model,
            'identity_source' => ProductGalleryGlobalContext::IDENTITY_COMBINED,
        ]);
    }

    /**
     * @param  list<int>  $originalSnapshotIds
     */
    private function createExecution(
        SeoArticle $article,
        string $executionId,
        ImageProviderCapabilities $caps,
        array $originalSnapshotIds,
    ): SeoProductGalleryExecution {
        return SeoProductGalleryExecution::query()->create([
            'execution_id' => $executionId,
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'generation_mode' => ProductGalleryGenerationMode::ParentChild->value,
            'status' => 'running',
            'provider_snapshot' => $caps->toArray(),
            'original_media_snapshot_ids' => $originalSnapshotIds,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function generateChildSerial(
        ProductGalleryParentChildAiPort $ai,
        SeoArticle $article,
        SeoProductGalleryExecution $execution,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
        int $retryCount,
    ): ?SeoMedia {
        $maxAttempts = max(1, $retryCount + 1);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $row = $this->runChildAttempt($ai, $article, $execution, $parent, $shot, $context, $variables, $attempt);
            if ($row->status === 'completed' && (int) ($row->generated_media_id ?? 0) > 0) {
                $media = SeoMedia::query()->find((int) $row->generated_media_id);

                return $media instanceof SeoMedia ? $media : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function runChildAttempt(
        ProductGalleryParentChildAiPort $ai,
        SeoArticle $article,
        SeoProductGalleryExecution $execution,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
        int $attempt,
    ): SeoProductGalleryChildAttempt {
        $row = SeoProductGalleryChildAttempt::query()->create([
            'execution_id' => (string) $execution->execution_id,
            'parent_execution_id' => (int) $execution->id,
            'parent_media_id' => (int) $parent->id,
            'slot_index' => $shot->slot,
            'shot_key' => $shot->shotKey,
            'shot_definition_snapshot' => $shot->toArray(),
            'attempt' => $attempt,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $media = $ai->generateChild($article, $parent, $shot, $context, $variables);
            if (! $media instanceof SeoMedia) {
                $row->update([
                    'status' => 'failed',
                    'failure_reason' => 'child_generate_empty',
                    'completed_at' => now(),
                ]);

                return $row->fresh() ?? $row;
            }

            $row->update([
                'status' => 'completed',
                'generated_media_id' => (int) $media->id,
                'completed_at' => now(),
            ]);

            return $row->fresh() ?? $row;
        } catch (Throwable $exception) {
            $row->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);

            return $row->fresh() ?? $row;
        }
    }

    private function tagParent(SeoMedia $parent, string $executionId): void
    {
        $variables = is_array($parent->prompt_variables) ? $parent->prompt_variables : [];
        $variables[ProductGalleryArtifactRole::KEY] = ProductGalleryArtifactRole::GENERATED_PARENT;
        $variables['gallery_generation_mode'] = ProductGalleryGenerationMode::ParentChild->value;
        $variables['gallery_execution_id'] = $executionId;
        $parent->update(['prompt_variables' => $variables]);
    }

    private function tagChild(
        SeoMedia $child,
        SeoMedia $parent,
        string $executionId,
        ProductGalleryShotDefinition $shot,
    ): void {
        $variables = is_array($child->prompt_variables) ? $child->prompt_variables : [];
        $variables[ProductGalleryArtifactRole::KEY] = ProductGalleryArtifactRole::GENERATED_CHILD_REFERENCE;
        $variables['gallery_generation_mode'] = ProductGalleryGenerationMode::ParentChild->value;
        $variables['gallery_execution_id'] = $executionId;
        $variables['parent_media_id'] = (int) $parent->id;
        $variables['slot_index'] = $shot->slot;
        $variables['shot_key'] = $shot->shotKey;
        $child->update(['prompt_variables' => $variables]);
    }

    /**
     * @param  list<int>  $originalSnapshotIds
     * @param  list<string>  $progress
     * @return array{
     *     selection: ProductGallerySelectionResult,
     *     execution_id: string,
     *     mode_resolution: array<string, mixed>,
     *     progress: list<string>,
     *     fell_back_to_sprite: bool
     * }
     */
    private function finishWithFallback(
        SeoArticle $article,
        SeoProductGalleryExecution $execution,
        array $originalSnapshotIds,
        mixed $modeResolution,
        array $progress,
        string $reason,
    ): array {
        $fallbackToSprite = false;
        try {
            $fallbackToSprite = (bool) config('seo-content-ai.product_gallery.parent_child.fallback_to_sprite', true);
        } catch (\Throwable) {
            $fallbackToSprite = true;
        }

        $selection = $this->selection->select(
            usableChildIds: [],
            rejectedChildIds: [],
            originalSnapshotIds: $originalSnapshotIds,
            mode: ProductGalleryGenerationMode::ParentChild,
            galleryExecutionId: (string) $execution->execution_id,
            historyExtra: [
                'fallback_reason' => $reason,
                'fallback_to_sprite_configured' => $fallbackToSprite,
                'mode_resolution' => is_object($modeResolution) && method_exists($modeResolution, 'toArray')
                    ? $modeResolution->toArray()
                    : (array) $modeResolution,
            ],
        );

        if ($selection->galleryReady && $selection->selectedMediaIds !== []) {
            $this->album->replaceAlbum($article, $selection->selectedMediaIds);
            $this->mirrorSelection($article, $selection, (string) $execution->execution_id);
        }

        $execution->update([
            'selection_snapshot' => $selection->toArray(),
            'status' => $selection->galleryReady ? 'completed_fallback' : 'failed',
            'completed_at' => now(),
        ]);

        $progress[] = $selection->galleryReady ? 'fallback_original' : 'no_usable_source';

        return [
            'selection' => $selection,
            'execution_id' => (string) $execution->execution_id,
            'mode_resolution' => is_object($modeResolution) && method_exists($modeResolution, 'toArray')
                ? $modeResolution->toArray()
                : (array) $modeResolution,
            'progress' => $progress,
            // Signal caller may run Mode 1 when originals empty and policy allows.
            'fell_back_to_sprite' => $fallbackToSprite && ! $selection->galleryReady,
        ];
    }

    private function mirrorSelection(SeoArticle $article, ProductGallerySelectionResult $selection, string $executionId): void
    {
        ProductGalleryReadyState::mirrorToArticle($article, array_merge($selection->toArray(), [
            'gallery_execution_id' => $executionId,
            'selected_media_ids' => $selection->selectedMediaIds,
        ]));
    }

    private function childRetryCount(): int
    {
        try {
            return max(0, (int) config('seo-content-ai.product_gallery.parent_child.child_retry_count', 1));
        } catch (\Throwable) {
            return 1;
        }
    }
}
