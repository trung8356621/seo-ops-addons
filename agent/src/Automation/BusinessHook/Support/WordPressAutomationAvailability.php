<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationAvailabilityGate;

/**
 * @deprecated Use AutomationAvailabilityGate — kept as thin facade for existing callers.
 */
final class WordPressAutomationAvailability
{
    public function __construct(
        private readonly AutomationAvailabilityGate $gate,
    ) {}

    public function isWordpressSyncEnabled(?int $siteId = null): bool
    {
        return $this->gate->isActionAvailableForManual(
            AutomationActionCode::WordpressArticleSync->value,
            $siteId,
        );
    }

    public function enabledWordpressSyncRuleCount(?int $siteId = null): int
    {
        return $this->gate->resolveManualRules(
            AutomationActionCode::WordpressArticleSync->value,
            $siteId,
        )->count();
    }

    public function disabledMessage(): string
    {
        return (string) __('seo-content-ai::filament.automation.gate.rule_disabled', [
            'action' => AutomationActionCode::WordpressArticleSync->value,
        ]);
    }
}
