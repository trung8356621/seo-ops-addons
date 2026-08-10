<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationConditionResult;

interface AgentAutomationConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $baseline
     * @param  list<string>  $allowedPaths
     */
    public function evaluate(
        array $condition,
        array $current,
        ?array $baseline,
        array $allowedPaths,
    ): AgentAutomationConditionResult;

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<string>  $allowedPaths
     * @return list<string>
     */
    public function validateSchema(array $condition, array $allowedPaths): array;
}
