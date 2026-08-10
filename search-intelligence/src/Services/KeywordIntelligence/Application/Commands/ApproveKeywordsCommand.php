<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ApproveKeywordsCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $keywordRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $keywordRefs,
        public readonly bool $approve = true,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.approve_keywords';
    }
}
