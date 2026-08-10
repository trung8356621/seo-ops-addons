<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class UpdateSerpQueryCommand implements ContentProjectCommand
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $queryRef,
        public readonly array $attributes,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.update_query';
    }
}
