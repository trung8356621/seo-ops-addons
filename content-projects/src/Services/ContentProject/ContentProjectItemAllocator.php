<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Shared write-time allocator for adding items into a Content Project chain.
 */
final class ContentProjectItemAllocator
{
    public function __construct(
        private readonly ContentProjectContinuationService $continuation,
    ) {}

    public function begin(SeoProject $source, bool $dryRun = false): ContentProjectAllocationSession
    {
        return new ContentProjectAllocationSession($source, $this->continuation, $dryRun);
    }
}
