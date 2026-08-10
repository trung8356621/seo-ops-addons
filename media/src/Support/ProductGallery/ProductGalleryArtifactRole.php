<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Artifact role metadata (Mode 1 + Mode 2 scaffold). Soft ownership only.
 */
final class ProductGalleryArtifactRole
{
    public const KEY = 'media_artifact_role';

    public const ORIGINAL = 'original';

    public const GENERATED_SPRITE = 'generated_sprite';

    public const GENERATED_CHILD = 'generated_child';

    public const GENERATED_PARENT = 'generated_parent';

    public const GENERATED_CHILD_REFERENCE = 'generated_child_reference';

    public static function isKnown(?string $role): bool
    {
        return in_array((string) $role, [
            self::ORIGINAL,
            self::GENERATED_SPRITE,
            self::GENERATED_CHILD,
            self::GENERATED_PARENT,
            self::GENERATED_CHILD_REFERENCE,
        ], true);
    }
}
