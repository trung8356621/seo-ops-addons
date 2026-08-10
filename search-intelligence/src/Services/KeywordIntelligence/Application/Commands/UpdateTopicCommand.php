<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class UpdateTopicCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $topicRef,
        public readonly array $attributes,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.update_topic';
    }
}
