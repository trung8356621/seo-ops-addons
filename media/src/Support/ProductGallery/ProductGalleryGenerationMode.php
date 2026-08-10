<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

enum ProductGalleryGenerationMode: string
{
    case Sprite = 'sprite';
    case ParentChild = 'parent_child';
    case Manual = 'manual';

    public static function fromLegacy(mixed $value): self
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return match ($raw) {
            'parent_child', 'parent_children' => self::ParentChild,
            'manual' => self::Manual,
            'sprite', 'mode_1', 'mode_1_validator_fallback', '' => self::Sprite,
            default => self::Sprite,
        };
    }
}
