<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ApplySerpIntentSuggestionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $evidenceRef,
        public readonly bool $preview = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.apply_intent';
    }
}
