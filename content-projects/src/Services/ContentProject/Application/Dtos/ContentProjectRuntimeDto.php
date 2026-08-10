<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

final class ContentProjectRuntimeDto
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public readonly string $projectRef,
        public readonly string $status,
        public readonly array $summary,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_ref' => $this->projectRef,
            'status' => $this->status,
            'summary' => $this->summary,
        ];
    }
}
