<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos;

final class ContentProjectDto
{
    /** @param array<string, mixed> $stats */
    public function __construct(
        public readonly string $projectRef,
        public readonly string $name,
        public readonly ?int $siteId,
        public readonly ?string $month,
        public readonly bool $archived,
        public readonly array $stats,
        public readonly ?string $createdAt,
        public readonly ?string $archivedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_ref' => $this->projectRef,
            'name' => $this->name,
            'site_id' => $this->siteId,
            'month' => $this->month,
            'archived' => $this->archived,
            'stats' => $this->stats,
            'created_at' => $this->createdAt,
            'archived_at' => $this->archivedAt,
        ];
    }
}
