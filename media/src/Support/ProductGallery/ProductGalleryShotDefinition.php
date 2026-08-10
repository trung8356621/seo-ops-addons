<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

final class ProductGalleryShotDefinition
{
    public const PRIORITY_REQUIRED = 'required';

    public const PRIORITY_OPTIONAL = 'optional';

    public function __construct(
        public readonly int $slot,
        public readonly string $shotKey,
        public readonly string $label,
        public readonly string $priority,
        public readonly string $aspectRatio,
        public readonly string $instruction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'shot_key' => $this->shotKey,
            'label' => $this->label,
            'priority' => $this->priority,
            'aspect_ratio' => $this->aspectRatio,
            'instruction' => $this->instruction,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            slot: (int) ($row['slot'] ?? 0),
            shotKey: trim((string) ($row['shot_key'] ?? '')),
            label: trim((string) ($row['label'] ?? '')),
            priority: strtolower(trim((string) ($row['priority'] ?? self::PRIORITY_REQUIRED))),
            aspectRatio: trim((string) ($row['aspect_ratio'] ?? '1:1')),
            instruction: trim((string) ($row['instruction'] ?? '')),
        );
    }
}
