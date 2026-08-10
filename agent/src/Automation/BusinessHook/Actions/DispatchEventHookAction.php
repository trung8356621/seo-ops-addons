<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;

final class DispatchEventHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly BusinessEventRegistry $eventRegistry,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $eventName = trim((string) ($settings['event_name'] ?? $input['event_name'] ?? ''));
        if ($eventName === '') {
            return AutomationActionResult::failure('EVENT_NAME_REQUIRED', 'settings.event_name is required.');
        }

        if (! $this->eventRegistry->has($eventName)) {
            return AutomationActionResult::failure('EVENT_NOT_REGISTERED', "Event [{$eventName}] is not registered.");
        }

        $payload = is_array($settings['payload'] ?? null)
            ? $settings['payload']
            : ($context->businessEvent->payload ?? []);

        return AutomationActionResult::success(
            output: ['dispatched_event' => $eventName],
            message: 'Follow-up event queued.',
            dispatchEvents: [[
                'event_name' => $eventName,
                'payload' => $payload,
                'context' => [
                    'from_rule' => $context->rule?->code,
                    'from_action' => 'automation.dispatch_event',
                ],
            ]],
        );
    }
}
