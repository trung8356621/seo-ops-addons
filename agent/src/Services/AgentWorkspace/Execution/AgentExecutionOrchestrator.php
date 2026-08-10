<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionCancellation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionConfirmation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionPreview;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRetry;

interface AgentExecutionOrchestrator
{
    public function preview(AgentExecutionRequest $request): AgentExecutionPreview;

    public function execute(AgentExecutionRequest $request): AgentExecutionResult;

    public function confirm(AgentExecutionConfirmation $confirmation): AgentExecutionResult;

    public function cancel(AgentExecutionCancellation $cancellation): AgentExecutionResult;

    public function retry(AgentExecutionRetry $retry): AgentExecutionResult;
}
