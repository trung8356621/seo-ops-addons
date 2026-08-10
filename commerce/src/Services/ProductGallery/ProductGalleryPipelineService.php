<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\AiPrompt\Services\PromptPostProcessingApplyService;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySelectionResult;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\SplitResult;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Product Gallery Mode 1 orchestrator (final contract).
 */
final class ProductGalleryPipelineService
{
    public function __construct(
        private readonly ProductSpriteValidator $validator,
        private readonly ProductGalleryImageDeduper $deduper,
        private readonly ProductGalleryChildValidator $childValidator,
        private readonly ProductGallerySelectionService $selection,
        private readonly PromptPostProcessingApplyService $postProcessing,
        private readonly ArticleMediaLocalService $articleMedia,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runAfterSpriteSaved(SeoMedia $sprite, SeoPrompt $prompt, SeoArticle $article): array
    {
        ProductGalleryReadyState::tagArtifactRole($sprite, ProductGalleryArtifactRole::GENERATED_SPRITE);

        $executionId = 'pg_'.bin2hex(random_bytes(8));
        $variables = is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [];
        $variables['gallery_generation_mode'] = ProductGalleryGenerationMode::Sprite->value;
        $variables['gallery_execution_id'] = $executionId;
        $sprite->update(['prompt_variables' => array_merge($variables, [
            ProductGalleryArtifactRole::KEY => ProductGalleryArtifactRole::GENERATED_SPRITE,
        ])]);
        $sprite->refresh();

        $state = ProductGalleryReadyState::readFromVariables(
            is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [],
        );

        if (($state['fallback_snapshot']['media_ids'] ?? []) === []
            && ($state['fallback_snapshot']['urls'] ?? []) === []
        ) {
            $state['fallback_snapshot'] = ProductGalleryReadyState::buildFallbackSnapshot(
                $this->articleMedia->resolveProductAlbum($article),
                ProductGalleryReadyState::ORIGIN_ALBUM_BEFORE_GENERATE,
            );
            $variables = ProductGalleryReadyState::mergeIntoVariables(
                is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [],
                [
                    'fallback_snapshot' => $state['fallback_snapshot'],
                    'original_media_snapshot_ids' => $state['fallback_snapshot']['media_ids'],
                    'gallery_execution_id' => $executionId,
                    'gallery_generation_mode' => ProductGalleryGenerationMode::Sprite->value,
                ],
            );
            $sprite->update(['prompt_variables' => $variables]);
            $sprite->refresh();
            $state = ProductGalleryReadyState::readFromVariables(
                is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [],
            );
        }

        $config = PromptPostProcessing::resolveFromMediaOrPrompt($sprite, $prompt);
        $grid = (int) ($config['split_grid_size'] ?? PromptPostProcessing::GRID_SIZE_DEFAULT);
        $absolutePath = $this->resolveAbsolutePath($sprite);
        $threshold = $this->validator->confidenceThreshold();

        $validation = $absolutePath !== null
            ? $this->validator->validate($absolutePath, $grid)
            : SpriteValidationResult::hardFail(
                'Sprite file missing for validation.',
                $grid,
                ['sprite_unreadable'],
                threshold: $threshold,
            );

        $usableChildIds = [];
        $rejectedChildIds = [];
        $splitPayload = null;
        $shouldSplit = ($config['split_enabled'] ?? false)
            && $validation->passesThreshold($threshold)
            && $validation->splitStrategy !== SpriteValidationResult::STRATEGY_NONE;

        if ($shouldSplit) {
            try {
                $splitResult = $this->runSplit($sprite, $prompt);
                $splitPayload = $splitResult->toArray();

                if ($splitResult->success && $splitResult->children !== []) {
                    $validated = $this->childValidator->validateChildren($splitResult->children);
                    foreach ($validated['usable_children'] as $index => $child) {
                        $this->tagChildMetadata($child, $sprite, $executionId, $index);
                        $usableChildIds[] = (int) $child->id;
                    }
                    foreach ($validated['rejected_children'] as $child) {
                        $rejectedChildIds[] = (int) $child->id;
                    }
                } elseif (! $splitResult->success) {
                    $splitPayload = $splitResult->toArray();
                }
            } catch (Throwable $exception) {
                $splitPayload = SplitResult::failed(
                    $exception->getMessage(),
                    'PRODUCT_GALLERY_SPLIT_EXCEPTION',
                )->toArray();
            }
        }

        $originalIds = $this->positiveIds($state['fallback_snapshot']['media_ids'] ?? []);
        $selection = $this->selection->select(
            usableChildIds: $usableChildIds,
            rejectedChildIds: $rejectedChildIds,
            originalSnapshotIds: $originalIds,
            mode: ProductGalleryGenerationMode::Sprite,
            validation: $validation,
            galleryExecutionId: $executionId,
            splitStrategy: $validation->splitStrategy,
            historyExtra: [
                'sprite_media_id' => (int) $sprite->id,
                'validator_reason_codes' => $validation->reasonCodes,
                'detected_panel_count' => $validation->detectedPanels,
                'gallery_generation_mode' => ProductGalleryGenerationMode::Sprite->value,
            ],
        );

        $this->persistSelection($article, $sprite, $selection, $validation, $splitPayload, $state);

        return array_merge($selection->toArray(), [
            'sprite_validation' => $validation->toArray(),
            'split' => $splitPayload,
            'album_items' => $this->albumItemsForSelection($selection, $state['fallback_snapshot'] ?? []),
        ]);
    }

    /**
     * Manual / retry split — no AI regenerate. Fail keeps original_images + gallery_ready.
     *
     * @param  list<array{id: int, url: string}>  $savedPieceRows
     * @return list<array{id: int, url: string}>
     */
    public function applyManualSplitRetry(
        SeoArticle $article,
        SeoMedia $sprite,
        array $savedPieceRows,
    ): array {
        $executionId = 'pg_retry_'.bin2hex(random_bytes(6));
        $variables = is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [];
        $state = ProductGalleryReadyState::readFromVariables($variables);
        $previousReady = (bool) ($state['gallery_ready'] ?? false);
        $previousSource = ProductGallerySource::fromLegacy($state['gallery_source'] ?? null);

        $appended = $this->postProcessing->finalizeProductGalleryManualSplit(
            $article,
            $sprite,
            $savedPieceRows,
        );

        $children = [];
        foreach ($appended as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $child = SeoMedia::query()->find($id);
            if ($child instanceof SeoMedia) {
                $children[] = $child;
            }
        }

        $validated = $this->childValidator->validateChildren($children);
        $usableIds = [];
        $rejectedIds = [];
        foreach ($validated['usable_children'] as $index => $child) {
            $this->tagChildMetadata($child, $sprite, $executionId, $index);
            $usableIds[] = (int) $child->id;
        }
        foreach ($validated['rejected_children'] as $child) {
            $rejectedIds[] = (int) $child->id;
        }

        ProductGalleryReadyState::tagArtifactRole($sprite, ProductGalleryArtifactRole::GENERATED_SPRITE);

        $originalIds = $this->positiveIds($state['fallback_snapshot']['media_ids'] ?? []);
        $selection = $this->selection->select(
            usableChildIds: $usableIds,
            rejectedChildIds: $rejectedIds,
            originalSnapshotIds: $originalIds,
            mode: ProductGalleryGenerationMode::Sprite,
            galleryExecutionId: $executionId,
            splitStrategy: SpriteValidationResult::STRATEGY_FIXED_GRID,
            historyExtra: [
                'sprite_media_id' => (int) $sprite->id,
                'retry_split' => true,
                'previous_source' => $previousSource->value,
            ],
        );

        // Retry fail must not flip gallery_ready false if originals still usable.
        if (! $selection->galleryReady && $previousReady && $originalIds !== []) {
            $selection = $this->selection->select(
                usableChildIds: [],
                rejectedChildIds: array_merge($usableIds, $rejectedIds),
                originalSnapshotIds: $originalIds,
                mode: ProductGalleryGenerationMode::Sprite,
                galleryExecutionId: $executionId,
                historyExtra: [
                    'sprite_media_id' => (int) $sprite->id,
                    'retry_split' => true,
                    'retry_failed_kept_original' => true,
                ],
            );
        }

        $this->persistSelection(
            $article,
            $sprite,
            $selection,
            SpriteValidationResult::fromArray(is_array($state['sprite_validation'] ?? null) ? $state['sprite_validation'] : []),
            [
                'success' => $usableIds !== [],
                'usable_child_ids' => $usableIds,
                'reason' => 'manual_split_retry',
            ],
            $state,
        );

        return $this->albumItemsForSelection($selection, $state['fallback_snapshot'] ?? []);
    }

    /**
     * Persist selection only after new album is ready; never auto-insert sprite.
     *
     * @param  array<string, mixed>|null  $splitPayload
     * @param  array<string, mixed>  $state
     */
    private function persistSelection(
        SeoArticle $article,
        SeoMedia $sprite,
        ProductGallerySelectionResult $selection,
        SpriteValidationResult $validation,
        ?array $splitPayload,
        array $state,
    ): void {
        $albumItems = $this->albumItemsForSelection($selection, $state['fallback_snapshot'] ?? []);

        DB::connection($article->getConnectionName())->transaction(function () use (
            $article,
            $sprite,
            $selection,
            $validation,
            $splitPayload,
            $state,
            $albumItems,
        ): void {
            if ($selection->galleryReady && $albumItems !== []) {
                // Persist new selection first — replaceProductAlbumLocal writes full album.
                $this->articleMedia->replaceProductAlbumLocal($article, $albumItems);
            }

            $nextState = [
                'gallery_ready' => $selection->galleryReady,
                'gallery_source' => $selection->gallerySource->value,
                'gallery_generation_mode' => $selection->galleryGenerationMode->value,
                'gallery_quality' => $selection->galleryQuality->value,
                'gallery_execution_id' => $selection->galleryExecutionId,
                'sprite_validation' => $validation->toArray(),
                'fallback_snapshot' => $state['fallback_snapshot'] ?? [],
                'original_media_snapshot_ids' => $state['fallback_snapshot']['media_ids'] ?? [],
                'child_media_ids' => $selection->gallerySource === ProductGallerySource::AiChildren
                    ? $selection->selectedMediaIds
                    : ($state['child_media_ids'] ?? []),
                'selected_media_ids' => $selection->selectedMediaIds,
                'split' => $splitPayload,
                'history' => $selection->history,
            ];

            $variables = ProductGalleryReadyState::mergeIntoVariables(
                is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [],
                $nextState,
            );
            $variables['gallery_execution_id'] = $selection->galleryExecutionId;
            $variables['gallery_generation_mode'] = $selection->galleryGenerationMode->value;
            $variables[ProductGalleryArtifactRole::KEY] = ProductGalleryArtifactRole::GENERATED_SPRITE;

            if ($selection->gallerySource === ProductGallerySource::AiChildren) {
                $variables['post_processing_piece_ids'] = $selection->selectedMediaIds;
                unset($variables['quick_split_error'], $variables['quick_split_error_code']);
            } else {
                $variables['quick_split_error'] = $selection->reason !== '' ? $selection->reason : $validation->reason;
                $variables['quick_split_error_code'] = $selection->reasonCodes[0]
                    ?? ($validation->reasonCodes[0] ?? 'SPRITE_VALIDATION_OR_SPLIT_FAILED');
            }

            $sprite->update(['prompt_variables' => $variables]);
            ProductGalleryReadyState::mirrorToArticle($article, $nextState);
        });
    }

    /**
     * @param  array{media_ids?: list<int>, urls?: list<string>}  $snapshot
     * @return list<array{id: int, url: string}>
     */
    private function albumItemsForSelection(ProductGallerySelectionResult $selection, array $snapshot): array
    {
        $items = [];
        foreach ($selection->selectedMediaIds as $id) {
            $media = SeoMedia::query()->find($id);
            if (! $media instanceof SeoMedia) {
                // Snapshot URL fallback for originals without local SeoMedia row.
                $urls = $snapshot['urls'] ?? [];
                $ids = $snapshot['media_ids'] ?? [];
                $idx = array_search($id, $ids, true);
                $url = is_int($idx) ? trim((string) ($urls[$idx] ?? '')) : '';
                if ($url === '') {
                    continue;
                }
                $items[] = ['id' => $id, 'url' => $url];

                continue;
            }
            // Never auto-insert sprite.
            if (ProductGalleryReadyState::artifactRole($media) === ProductGalleryArtifactRole::GENERATED_SPRITE) {
                continue;
            }
            $items[] = [
                'id' => (int) $media->id,
                'url' => $media->publicUrl(),
            ];
        }

        return $this->deduper->dedupe($items);
    }

    private function tagChildMetadata(SeoMedia $child, SeoMedia $sprite, string $executionId, int $slotIndex): void
    {
        $variables = is_array($child->prompt_variables) ? $child->prompt_variables : [];
        $variables[ProductGalleryArtifactRole::KEY] = ProductGalleryArtifactRole::GENERATED_CHILD;
        $variables['gallery_generation_mode'] = ProductGalleryGenerationMode::Sprite->value;
        $variables['gallery_execution_id'] = $executionId;
        $variables['source_sprite_media_id'] = (int) $sprite->id;
        $variables['slot_index'] = $slotIndex;
        $variables['post_processing_source_media_id'] = (int) $sprite->id;
        $child->update(['prompt_variables' => $variables]);
    }

    private function runSplit(SeoMedia $sprite, SeoPrompt $prompt): SplitResult
    {
        $result = $this->postProcessing->applyConfiguredSplitForProductGallery($sprite, $prompt);
        if (! $result->applied || $result->pieces === []) {
            return SplitResult::failed(
                (string) ($result->message ?? 'Split failed.'),
                $result->errorCode,
            );
        }

        return SplitResult::ok(
            children: $result->pieces,
            usableChildren: $result->pieces,
            reason: $result->message,
        );
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0)));
    }

    private function resolveAbsolutePath(SeoMedia $media): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($relativePath === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($relativePath)) {
            return null;
        }

        return $disk->path($relativePath);
    }
}
