<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Immutable gallery execution context — snapshot once before children run.
 */
final class ProductGalleryGlobalContext
{
    public const IDENTITY_METADATA = 'metadata';

    public const IDENTITY_PARENT_REFERENCE = 'parent_reference';

    public const IDENTITY_COMBINED = 'combined';

    /**
     * @param  list<int>  $originalMediaIds
     * @param  list<string>  $distinctiveFeatures
     * @param  list<string>  $negativeConstraints
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $executionId,
        public readonly int $articleId,
        public readonly string $productIdentity,
        public readonly string $title,
        public readonly string $brand,
        public readonly string $category,
        public readonly string $primaryColor,
        public readonly string $secondaryColor,
        public readonly string $material,
        public readonly string $shape,
        public readonly string $logoPosition,
        public readonly string $hardware,
        public readonly ?int $strapCount,
        public readonly ?int $pocketCount,
        public readonly array $distinctiveFeatures,
        public readonly array $negativeConstraints,
        public readonly array $originalMediaIds,
        public readonly ?int $parentMediaId,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $identitySource,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            executionId: (string) ($data['execution_id'] ?? ''),
            articleId: (int) ($data['article_id'] ?? 0),
            productIdentity: (string) ($data['product_identity'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            brand: (string) ($data['brand'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            primaryColor: (string) ($data['primary_color'] ?? ''),
            secondaryColor: (string) ($data['secondary_color'] ?? ''),
            material: (string) ($data['material'] ?? ''),
            shape: (string) ($data['shape'] ?? ''),
            logoPosition: (string) ($data['logo_position'] ?? ''),
            hardware: (string) ($data['hardware'] ?? ''),
            strapCount: isset($data['strap_count']) ? (int) $data['strap_count'] : null,
            pocketCount: isset($data['pocket_count']) ? (int) $data['pocket_count'] : null,
            distinctiveFeatures: array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                is_array($data['distinctive_features'] ?? null) ? $data['distinctive_features'] : [],
            )),
            negativeConstraints: array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                is_array($data['negative_constraints'] ?? null) ? $data['negative_constraints'] : [],
            )),
            originalMediaIds: array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($data['original_media_ids'] ?? null) ? $data['original_media_ids'] : [],
            ), static fn (int $id): bool => $id > 0)),
            parentMediaId: isset($data['parent_media_id']) ? (int) $data['parent_media_id'] : null,
            provider: (string) ($data['provider'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            identitySource: (string) ($data['identity_source'] ?? self::IDENTITY_METADATA),
            extra: is_array($data['extra'] ?? null) ? $data['extra'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'article_id' => $this->articleId,
            'product_identity' => $this->productIdentity,
            'title' => $this->title,
            'brand' => $this->brand,
            'category' => $this->category,
            'primary_color' => $this->primaryColor,
            'secondary_color' => $this->secondaryColor,
            'material' => $this->material,
            'shape' => $this->shape,
            'logo_position' => $this->logoPosition,
            'hardware' => $this->hardware,
            'strap_count' => $this->strapCount,
            'pocket_count' => $this->pocketCount,
            'distinctive_features' => $this->distinctiveFeatures,
            'negative_constraints' => $this->negativeConstraints,
            'original_media_ids' => $this->originalMediaIds,
            'parent_media_id' => $this->parentMediaId,
            'provider' => $this->provider,
            'model' => $this->model,
            'identity_source' => $this->identitySource,
            'extra' => $this->extra,
        ];
    }

    public function withParentMediaId(int $parentMediaId): self
    {
        $data = $this->toArray();
        $data['parent_media_id'] = $parentMediaId;
        if ($this->identitySource === self::IDENTITY_METADATA) {
            $data['identity_source'] = self::IDENTITY_COMBINED;
        }

        return self::fromArray($data);
    }
}
