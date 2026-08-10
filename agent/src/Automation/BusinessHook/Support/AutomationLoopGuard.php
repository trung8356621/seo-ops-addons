<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;

final class AutomationLoopGuard
{
    public const MAX_DEPTH = 10;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function assertAllowed(array $context, string $eventName, ?int $ruleId = null): array
    {
        $depth = (int) ($context['automation_depth'] ?? 0);
        if ($depth >= self::MAX_DEPTH) {
            throw new AutomationException(
                BusinessHookErrorCode::MaxDepthExceeded->value,
                'Automation max depth exceeded.',
            );
        }

        $chain = $context['automation_chain'] ?? [];
        if (! is_array($chain)) {
            $chain = [];
        }

        if ($ruleId !== null) {
            $signature = $eventName.'#'.$ruleId;
            if (in_array($signature, $chain, true) && ! ($context['allow_event_rule_loop'] ?? false)) {
                throw new AutomationException(
                    BusinessHookErrorCode::LoopDetected->value,
                    "Automation loop detected for [{$signature}].",
                );
            }
            $chain[] = $signature;
        }

        $context['automation_depth'] = $depth + 1;
        $context['automation_chain'] = $chain;
        if (! isset($context['root_event_uuid']) && isset($context['event_uuid'])) {
            $context['root_event_uuid'] = $context['event_uuid'];
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $parentContext
     * @return array<string, mixed>
     */
    public function childContext(array $parentContext, string $eventName, int $ruleId): array
    {
        $context = $parentContext;
        $context['event_uuid'] = $parentContext['event_uuid'] ?? null;

        return $this->assertAllowed($context, $eventName, $ruleId);
    }
}
