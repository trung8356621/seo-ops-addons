<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Typed gallery selection outcome — shared by Content Project, modal, auto-insert.
 */
final class ProductGallerySelectionResult
{
    /**
     * @param  list<int>  $selectedMediaIds
     * @param  list<int>  $rejectedMediaIds
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $history
     */
    public function __construct(
        public readonly bool $galleryReady,
        public readonly ProductGallerySource $gallerySource,
        public readonly ProductGalleryGenerationMode $galleryGenerationMode,
        public readonly ProductGalleryQuality $galleryQuality,
        public readonly array $selectedMediaIds = [],
        public readonly array $rejectedMediaIds = [],
        public readonly string $reason = '',
        public readonly array $reasonCodes = [],
        public readonly ?string $galleryExecutionId = null,
        public readonly array $history = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gallery_ready' => $this->galleryReady,
            'gallery_source' => $this->gallerySource->value,
            'gallery_generation_mode' => $this->galleryGenerationMode->value,
            'gallery_quality' => $this->galleryQuality->value,
            'selected_media_ids' => array_values($this->selectedMediaIds),
            'rejected_media_ids' => array_values($this->rejectedMediaIds),
            'reason' => $this->reason,
            'reason_codes' => array_values($this->reasonCodes),
            'gallery_execution_id' => $this->galleryExecutionId,
            'history' => $this->history,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            galleryReady: (bool) ($data['gallery_ready'] ?? false),
            gallerySource: ProductGallerySource::fromLegacy($data['gallery_source'] ?? null),
            galleryGenerationMode: ProductGalleryGenerationMode::fromLegacy($data['gallery_generation_mode'] ?? null),
            galleryQuality: ProductGalleryQuality::fromLegacy($data['gallery_quality'] ?? null),
            selectedMediaIds: array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($data['selected_media_ids'] ?? null) ? $data['selected_media_ids'] : [],
            ), static fn (int $id): bool => $id > 0)),
            rejectedMediaIds: array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($data['rejected_media_ids'] ?? null) ? $data['rejected_media_ids'] : [],
            ), static fn (int $id): bool => $id > 0)),
            reason: (string) ($data['reason'] ?? ''),
            reasonCodes: array_values(array_map(
                static fn (mixed $c): string => (string) $c,
                is_array($data['reason_codes'] ?? null) ? $data['reason_codes'] : [],
            )),
            galleryExecutionId: isset($data['gallery_execution_id']) ? (string) $data['gallery_execution_id'] : null,
            history: is_array($data['history'] ?? null) ? $data['history'] : [],
        );
    }
}
