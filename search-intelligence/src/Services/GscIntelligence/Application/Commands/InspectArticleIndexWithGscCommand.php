<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class InspectArticleIndexWithGscCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $articleId,
    ) {}

    public function name(): string
    {
        return 'article.index_health.inspect_gsc';
    }
}
