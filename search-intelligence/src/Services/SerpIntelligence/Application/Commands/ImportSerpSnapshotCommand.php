<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ImportSerpSnapshotCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $queryRef,
        public readonly string $payload,
        public readonly string $format = 'json',
        public readonly bool $preview = false,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.import_snapshot';
    }
}
