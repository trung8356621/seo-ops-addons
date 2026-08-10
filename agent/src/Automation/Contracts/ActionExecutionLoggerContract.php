<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Contracts;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;

interface ActionExecutionLoggerContract
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function start(
        ActionContext $context,
        string $actionKey,
        ?string $entityType,
        ?int $entityId,
        array $input,
    ): void;

    public function finish(string $executionId, ActionResult $result): void;
}
