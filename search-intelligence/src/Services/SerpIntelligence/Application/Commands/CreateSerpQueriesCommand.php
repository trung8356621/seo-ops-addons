<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateSerpQueriesCommand implements ContentProjectCommand
{
    /** @param list<array<string, mixed>> $queries */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $queries,
        public readonly ?string $providerKey = null,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.create_queries';
    }
}
