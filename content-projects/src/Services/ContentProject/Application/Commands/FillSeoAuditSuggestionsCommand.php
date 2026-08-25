<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class FillSeoAuditSuggestionsCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $filters = [],
        public readonly int|string $limit = 20,
    ) {}

    public function name(): string
    {
        return 'content_project.fill_seo_audit_suggestions';
    }
}
