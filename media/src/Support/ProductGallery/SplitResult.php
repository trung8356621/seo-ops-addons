<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

use Omnichannel\Addons\Media\Models\SeoMedia;

final class SplitResult
{
    /**
     * @param  list<SeoMedia>  $children
     * @param  list<SeoMedia>  $usableChildren
     * @param  list<SeoMedia>  $invalidChildren
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $children = [],
        public readonly array $usableChildren = [],
        public readonly array $invalidChildren = [],
        public readonly ?string $reason = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function failed(string $reason, ?string $errorCode = null): self
    {
        return new self(
            success: false,
            reason: $reason,
            errorCode: $errorCode,
        );
    }

    /**
     * @param  list<SeoMedia>  $children
     * @param  list<SeoMedia>  $usableChildren
     * @param  list<SeoMedia>  $invalidChildren
     */
    public static function ok(
        array $children,
        array $usableChildren,
        array $invalidChildren = [],
        ?string $reason = null,
    ): self {
        return new self(
            success: $usableChildren !== [],
            children: $children,
            usableChildren: $usableChildren,
            invalidChildren: $invalidChildren,
            reason: $reason,
        );
    }

    /**
     * @return list<int>
     */
    public function usableChildIds(): array
    {
        return array_values(array_map(
            static fn (SeoMedia $media): int => (int) $media->id,
            $this->usableChildren,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'child_ids' => array_values(array_map(
                static fn (SeoMedia $media): int => (int) $media->id,
                $this->children,
            )),
            'usable_child_ids' => $this->usableChildIds(),
            'invalid_child_ids' => array_values(array_map(
                static fn (SeoMedia $media): int => (int) $media->id,
                $this->invalidChildren,
            )),
            'reason' => $this->reason,
            'error_code' => $this->errorCode,
        ];
    }
}
