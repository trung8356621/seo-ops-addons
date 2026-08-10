<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;

/**
 * Rule adapter → catalog `keyword.domain_link_list.sync`.
 */
final class SyncKeywordDomainLinkListHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly BusinessActionDispatcher $dispatcher,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $eventPayload = is_array($context->businessEvent->payload ?? null)
            ? $context->businessEvent->payload
            : [];
        $payload = array_merge($eventPayload, $input, $settings);

        $siteId = (int) ($payload['site_id'] ?? $context->siteId ?? 0) ?: null;

        $result = $this->dispatcher->dispatch(
            'keyword.domain_link_list.sync',
            [
                'keyword_id' => (int) ($payload['keyword_id'] ?? 0) ?: null,
                'site_id' => $siteId,
                'phrase' => (string) ($payload['phrase'] ?? ''),
                'target_url' => (string) ($payload['target_url'] ?? ''),
                'previous_phrase' => (string) ($payload['previous_phrase'] ?? ''),
                'operation' => (string) ($payload['operation'] ?? 'upsert'),
            ],
            ActionContext::fromArray([
                'origin' => 'rule.keyword.domain_link_list.sync',
                'correlation_id' => (string) ($context->correlationId ?? $context->execution->correlation_id ?? ''),
                'causation_id' => (string) ($context->businessEvent->event_uuid ?? $context->execution->id ?? ''),
                'actor_id' => $context->actorId,
                'site_id' => $siteId,
            ]),
        );

        if (! $result->success) {
            return AutomationActionResult::failure(
                (string) ($result->error['code'] ?? 'DOMAIN_LINK_LIST_SYNC_FAILED'),
                (string) ($result->error['message'] ?? 'Domain link list sync failed.'),
                $result->output,
            );
        }

        return AutomationActionResult::success(
            output: $result->output,
            message: 'Domain link list synced.',
        );
    }
}
