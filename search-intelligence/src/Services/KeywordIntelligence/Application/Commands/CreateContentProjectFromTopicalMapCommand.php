<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateContentProjectFromTopicalMapCommand implements ContentProjectCommand
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
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.create_content_project';
    }
}
