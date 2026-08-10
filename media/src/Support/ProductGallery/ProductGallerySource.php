<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

enum ProductGallerySource: string
{
    case Pending = 'pending';
    case AiChildren = 'ai_children';
    case OriginalImages = 'original_images';
    case ParentChildren = 'parent_children';
    case Manual = 'manual';

    /**
     * Map legacy stored values (e.g. original_fallback) without rewriting DB.
     */
    public static function fromLegacy(mixed $value): self
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return match ($raw) {
            'ai_children', 'ai' => self::AiChildren,
            'original_images', 'original', 'original_fallback', 'fallback' => self::OriginalImages,
            'parent_children', 'parent_child' => self::ParentChildren,
            'manual' => self::Manual,
            'pending', '' => self::Pending,
            default => self::Pending,
        };
    }
}
