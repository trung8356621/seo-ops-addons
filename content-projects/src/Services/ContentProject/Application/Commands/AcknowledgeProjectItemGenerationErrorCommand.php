<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Clear stale generation failure on latest run-item without re-running AI.
 * Use when content/domain write already OK but row still shows Failed + error banner.
 *
 * @param  list<int|string>  $itemRefs
 */
final class AcknowledgeProjectItemGenerationErrorCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly ?string $note = null,
    ) {}

    public function name(): string
    {
        return 'content_project.acknowledge_generation_error';
    }
}
