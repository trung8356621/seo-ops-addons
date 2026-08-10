<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RejectSiteProfileSuggestionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $suggestionHash,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.reject_profile_suggestion';
    }
}
