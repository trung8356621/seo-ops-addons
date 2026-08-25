<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RestoreSeoAuditSuggestionsCommand implements ContentProjectCommand
{
    /**
     * @param  list<int>  $articleIds
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $articleIds,
    ) {}

    public function name(): string
    {
        return 'content_project.restore_seo_audit_suggestions';
    }
}
