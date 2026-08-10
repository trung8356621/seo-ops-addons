<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

final class ContentProjectSummaryDto
{
    /** @param array<string, int> $stats */
    public function __construct(
        public readonly string $projectRef,
        public readonly array $stats,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_ref' => $this->projectRef,
            'stats' => $this->stats,
        ];
    }
}
