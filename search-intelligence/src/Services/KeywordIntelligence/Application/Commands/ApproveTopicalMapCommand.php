<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ApproveTopicalMapCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $mapVersionRef,
        public readonly bool $allowBlockingOverride = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.approve_topical_map';
    }
}
