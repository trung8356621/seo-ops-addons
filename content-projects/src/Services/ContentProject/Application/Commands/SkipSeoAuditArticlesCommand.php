<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Global skip: article_meta.skip_seo_audit = 1 (not project rejection).
 */
final class SkipSeoAuditArticlesCommand implements ContentProjectCommand
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
        return 'content_project.skip_seo_audit_articles';
    }
}
