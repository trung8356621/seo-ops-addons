<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

final class ProductGalleryPlan
{
    /**
     * @param  list<ProductGalleryShotDefinition>  $shots
     */
    public function __construct(
        public readonly array $shots,
        public readonly int $requestedImageCount,
    ) {}

    /**
     * @return array{shots: list<array<string, mixed>>, requested_image_count: int}
     */
    public function toArray(): array
    {
        return [
            'shots' => array_values(array_map(
                static fn (ProductGalleryShotDefinition $shot): array => $shot->toArray(),
                $this->shots,
            )),
            'requested_image_count' => $this->requestedImageCount,
        ];
    }

    public function requiredCount(): int
    {
        return count(array_filter(
            $this->shots,
            static fn (ProductGalleryShotDefinition $s): bool => $s->priority === ProductGalleryShotDefinition::PRIORITY_REQUIRED,
        ));
    }
}
