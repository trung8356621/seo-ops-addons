<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Foundation;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;

/**
 * Foundation smoke action — không side effect nghiệp vụ.
 */
final class PingAction implements BusinessAction
{
    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'automation.ping',
            name: 'Automation ping',
            description: 'Foundation health-check action (pure).',
            module: 'automation',
            sideEffect: ActionSideEffect::Pure,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::InternalOnly,
            inputSchema: [
                'message' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'pong' => ['type' => 'boolean'],
            ],
            supportsDryRun: true,
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        return ActionResult::success(
            output: [
                'pong' => true,
                'message' => (string) ($input['message'] ?? 'ok'),
                'execution_id' => $context->executionId,
            ],
        );
    }
}
