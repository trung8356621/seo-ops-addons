<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentSummarizationRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $workingContext
     */
    public function __construct(
        public array $messages,
        public array $workingContext = [],
        public string $locale = 'vi',
    ) {}
}
