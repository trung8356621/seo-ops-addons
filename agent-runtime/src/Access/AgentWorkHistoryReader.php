<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

interface AgentWorkHistoryReader
{
    /**
     * @return array{
     *     planned: int,
     *     entered_workflow: int,
     *     completed: int,
     *     outstanding: int,
     *     archived: int,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function inspect(AgentActorAccessContext $context, int $page, int $perPage): array;
}
