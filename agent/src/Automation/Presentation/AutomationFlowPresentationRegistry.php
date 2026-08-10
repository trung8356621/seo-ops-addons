<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation;

/**
 * Human-readable labels for Automation Flows UI — presentation only.
 * Does not participate in execution.
 */
final class AutomationFlowPresentationRegistry
{
    public function eventLabel(string $eventName): string
    {
        $key = 'seo-content-ai::filament.automation.flows.events.'.$eventName;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($eventName);
    }

    public function actionLabel(string $actionCode): string
    {
        $key = 'seo-content-ai::filament.automation.flows.actions.'.$actionCode;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($actionCode);
    }

    public function capabilityLabel(string $capabilityId): string
    {
        $key = 'seo-content-ai::filament.automation.flows.capabilities.'.$capabilityId;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($capabilityId);
    }

    public function categoryLabel(string $category): string
    {
        $key = 'seo-content-ai::filament.automation.flows.categories.'.$category;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($category);
    }

    public function statusLabel(string $status): string
    {
        $key = 'seo-content-ai::filament.automation.flows.status.'.$status;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($status);
    }

    public function mappingLabel(string $mappingStatus): string
    {
        $key = 'seo-content-ai::filament.automation.flows.mapping.'.$mappingStatus;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($mappingStatus);
    }

    public function edgeTypeLabel(string $edgeType): string
    {
        $key = 'seo-content-ai::filament.automation.flows.edge_types.'.$edgeType;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($edgeType);
    }

    public function workflowLabel(string $workflowId): string
    {
        $normalized = str_replace(['wf:', '-'], ['', '_'], $workflowId);
        $key = 'seo-content-ai::filament.automation.flows.workflows.'.$normalized.'.name';
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return $this->fallbackLabel($workflowId);
    }

    public function fallbackLabel(string $identifier): string
    {
        $normalized = str_replace(['_', '-', '.'], ' ', $identifier);

        return ucwords(trim($normalized));
    }
}
