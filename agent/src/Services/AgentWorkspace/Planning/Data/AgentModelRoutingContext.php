<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentModelRoutingContext
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $taskType,
        public int $estimatedInputTokens = 0,
        public bool $requiresStructuredOutput = true,
        public ?string $userSelectedModel = null,
        public ?int $connectionId = null,
        public ?string $siteRef = null,
        public bool $allowFallback = true,
        public array $meta = [],
    ) {}
}
