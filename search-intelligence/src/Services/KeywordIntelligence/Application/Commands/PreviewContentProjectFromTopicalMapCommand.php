<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class PreviewContentProjectFromTopicalMapCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>|null  $clusterRefs
     * @param  array<string, mixed>  $projectAttributes
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $mapVersionRef,
        public readonly string $policy = 'new_only',
        public readonly ?array $clusterRefs = null,
        public readonly array $projectAttributes = [],
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.preview_content_project';
    }
}
