<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\Core;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\DelayHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\DispatchEventHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\NotificationSendHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\WebhookSendHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;

/**
 * Core automation primitives — không phụ thuộc domain (WP, SEO, Content Project, AI).
 */
final class CoreAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'automation.core';
    }

    public function register(AutomationModuleContext $context): void
    {
        $context->events->register(new BusinessEventDefinition(
            name: BusinessEventName::ScheduleTriggered->value,
            subject: null,
            payloadSchema: [
                'rule_id' => ['type' => 'integer', 'required' => true],
                'rule_code' => ['type' => 'string', 'required' => false],
                'scheduled_at' => ['type' => 'string', 'required' => false],
            ],
            description: BusinessEventName::ScheduleTriggered->value,
            module: 'automation',
        ));

        $context->events->register(new BusinessEventDefinition(
            name: BusinessEventName::ManualActionRequested->value,
            subject: null,
            payloadSchema: [
                'action_code' => ['type' => 'string', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => false],
            ],
            description: 'Manual Automation Action requested from UI/API.',
            module: 'automation',
        ));

        $context->events->register(new BusinessEventDefinition(
            name: BusinessEventName::NotificationRequested->value,
            subject: null,
            payloadSchema: [
                'message' => ['type' => 'string', 'required' => false],
                'title' => ['type' => 'string', 'required' => false],
                'user_id' => ['type' => 'integer', 'required' => false],
                'project_id' => ['type' => 'integer', 'required' => false],
            ],
            description: 'Request in-app notification delivery via Automation.',
            module: 'notification',
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::WebhookSend->value,
            handlerClass: WebhookSendHookAction::class,
            inputRules: [],
            settingsRules: [
                'url' => ['type' => 'string', 'required' => true],
            ],
            description: 'Send HTTP webhook from server-side settings.',
            isAsyncSafe: true,
            timeout: 30,
            module: 'integration',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'webhook',
            maxAttemptsPerMinute: 60,
            fieldMeta: [
                'url' => ['label' => 'Webhook URL', 'type' => 'url', 'source' => 'settings', 'required' => true],
            ],
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::NotificationSend->value,
            handlerClass: NotificationSendHookAction::class,
            inputRules: [
                'message' => ['type' => 'string', 'required' => false],
            ],
            settingsRules: [],
            description: 'Send in-app notification (wrap existing notification path).',
            isAsyncSafe: true,
            timeout: 15,
            module: 'notification',
            defaultQueue: AutomationQueueName::Automation->value,
            supportsTest: true,
            fieldMeta: [
                'message' => ['label' => 'Message', 'type' => 'textarea', 'source' => 'input'],
            ],
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::Delay->value,
            handlerClass: DelayHookAction::class,
            inputRules: [],
            settingsRules: [
                'seconds' => ['type' => 'integer', 'required' => false],
            ],
            description: 'No-op marker; real delay uses action delay_seconds / job delay.',
            isAsyncSafe: true,
            timeout: 5,
            module: 'automation',
            defaultQueue: AutomationQueueName::Automation->value,
            supportsTest: true,
            fieldMeta: [
                'seconds' => ['label' => 'Delay seconds', 'type' => 'integer', 'source' => 'settings', 'min' => 0],
            ],
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::AutomationDispatchEvent->value,
            handlerClass: DispatchEventHookAction::class,
            inputRules: [],
            settingsRules: [
                'event_name' => ['type' => 'string', 'required' => true],
            ],
            description: 'Dispatch another business event with loop protection.',
            isAsyncSafe: true,
            timeout: 15,
            module: 'automation',
            defaultQueue: AutomationQueueName::Automation->value,
            fieldMeta: [
                'event_name' => ['label' => 'Event name', 'type' => 'event_select', 'source' => 'settings', 'required' => true],
            ],
        ));
    }
}
