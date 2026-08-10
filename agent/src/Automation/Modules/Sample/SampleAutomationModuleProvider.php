<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\Sample;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Platform\Data\ConditionOperatorDefinition;
use Omnichannel\Addons\Agent\Automation\Platform\Data\HealthCheckDefinition;
use Omnichannel\Addons\Agent\Automation\Platform\Data\MenuItemDefinition;
use Omnichannel\Addons\Agent\Automation\Platform\Data\PermissionDefinition;
use Omnichannel\Addons\Agent\Automation\Platform\Data\SettingDefinition;

/**
 * Ví dụ Module SDK — disabled mặc định trong config/automation-modules.php.
 */
final class SampleAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'sample';
    }

    public function register(AutomationModuleContext $context): void
    {
        $context->events->register(new BusinessEventDefinition(
            name: 'sample.ping',
            subject: null,
            payloadSchema: [
                'message' => ['type' => 'string', 'required' => false],
            ],
            description: 'Sample ping event for SDK documentation.',
            module: 'sample',
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: 'sample.echo',
            handlerClass: SampleEchoHookAction::class,
            inputRules: [
                'message' => ['type' => 'string', 'required' => false],
            ],
            settingsRules: [],
            description: 'Sample no-op echo action.',
            isAsyncSafe: true,
            timeout: 5,
            module: 'sample',
            supportsTest: true,
        ));

        $context->conditions->registerOperator(new ConditionOperatorDefinition(
            name: 'sample_starts_with',
            evaluator: static fn (mixed $actual, mixed $expected): bool => is_string($actual)
                && is_string($expected)
                && str_starts_with($actual, $expected),
            description: 'Sample custom operator: string starts with expected.',
            module: 'sample',
        ));

        $context->conditions->registerFieldRoots('sample', ['sample']);

        $context->healthChecks->register(new HealthCheckDefinition(
            key: 'sample.module_alive',
            module: 'sample',
            checker: static fn (): array => ['status' => 'ok', 'message' => 'Sample module registered'],
            description: 'Sample health probe.',
        ));

        $context->menus->register(new MenuItemDefinition(
            key: 'sample.dashboard',
            label: 'Sample Module',
            module: 'sample',
            route: null,
            group: 'automation',
        ));

        $context->permissions->register(new PermissionDefinition(
            key: 'automation.sample.view',
            label: 'View sample automation module',
            module: 'sample',
        ));

        $context->settings->register(new SettingDefinition(
            key: 'sample.greeting',
            module: 'sample',
            label: 'Sample greeting',
            schema: ['type' => 'string'],
            default: 'hello',
        ));
    }
}

final class SampleEchoHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        return AutomationActionResult::success(
            output: ['echo' => (string) ($input['message'] ?? '')],
            message: 'Sample echo OK.',
        );
    }
}
