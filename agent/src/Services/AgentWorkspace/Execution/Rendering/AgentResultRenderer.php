<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

interface AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool;

    /**
     * @return array{
     *     title: string,
     *     summary: string,
     *     metrics?: array<string, mixed>,
     *     badges?: list<string>,
     *     warnings?: list<string>,
     *     links?: list<array{label: string, href?: string|null, ref?: string|null, type?: string}>,
     *     suggested_skills?: list<array{skill_key: string, name?: string}>,
     *     operation_reference?: string|null,
     *     details?: array<string, mixed>
     * }
     */
    public function render(AgentExecutionResult $result): array;
}
