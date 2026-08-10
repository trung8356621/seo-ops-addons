<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeSearchResult
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $items,
        public int $omittedCount = 0,
        public array $diagnostics = [],
    ) {}
}
