<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Batch inspect: explicit article_ids and/or due mode.
 *
 * @param  list<int>  $articleIds
 */
final class InspectArticleIndexesWithGscCommand implements ContentProjectCommand
{
    /**
     * @param  list<int>  $articleIds
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $articleIds = [],
        public readonly bool $dueOnly = false,
        public readonly ?int $limit = null,
    ) {}

    public function name(): string
    {
        return 'article.index_health.inspect_due_gsc';
    }
}
