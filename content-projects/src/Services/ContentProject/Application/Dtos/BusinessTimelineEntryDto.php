<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

final class BusinessTimelineEntryDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $at,
        public readonly bool $done,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'at' => $this->at,
            'done' => $this->done,
        ];
    }
}
