<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Manually attach an existing same-site Laravel Article to a rewrite/improve item.
 * Does not start AI generation — capability resolver decides Resume / Run again next.
 */
final class SelectExistingArticleForProjectItemCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string|int $itemRef,
        public readonly int $articleId,
    ) {}

    public function name(): string
    {
        return 'content_project.select_existing_article';
    }
}
