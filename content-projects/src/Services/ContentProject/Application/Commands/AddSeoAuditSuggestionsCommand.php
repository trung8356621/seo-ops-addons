<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AddSeoAuditSuggestionsCommand implements ContentProjectCommand
{
    /**
     * @param  list<array{article_id:int, action?:string, reason_codes?:list<string>, recommendation_summary?:string}>  $rows
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly int $siteId,
        public readonly array $rows,
    ) {}

    public function name(): string
    {
        return 'content_project.add_seo_audit_suggestions';
    }
}
