<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ReviewTopicalMapCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $mapVersionRef,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.review_topical_map';
    }
}
