<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

enum ProductGalleryQuality: string
{
    case Perfect = 'perfect';
    case Usable = 'usable';
    case Fallback = 'fallback';
    case Manual = 'manual';

    public static function fromLegacy(mixed $value): self
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return match ($raw) {
            'perfect' => self::Perfect,
            'usable' => self::Usable,
            'manual' => self::Manual,
            'fallback', '' => self::Fallback,
            default => self::Fallback,
        };
    }
}
