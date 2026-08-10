<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ReanalyzeSerpPageEvidenceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $pageEvidenceRef,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.reanalyze_page_evidence';
    }
}
