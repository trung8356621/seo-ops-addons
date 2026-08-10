<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class UpdateKeywordClassificationCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $keywordRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $keywordRefs,
        public readonly ?string $searchIntent = null,
        public readonly ?string $funnelStage = null,
        public readonly ?float $businessValue = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.update_keyword';
    }
}
