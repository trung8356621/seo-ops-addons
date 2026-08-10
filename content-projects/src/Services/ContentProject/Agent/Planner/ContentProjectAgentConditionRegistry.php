<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;

/**
 * Whitelist conditions evaluated via Gateway read tools only.
 */
final class ContentProjectAgentConditionRegistry
{
    /** @var list<string> */
    public const WHITELIST = [
        'all_items_generated',
        'all_items_reviewed',
        'all_items_approved',
        'schedule_reached',
        'operation_completed',
        'site_healthy',
        'quota_available',
    ];

    public function __construct(
        private readonly ContentProjectAgentGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function evaluate(AgentExecutionContext $context, string $condition, array $payload = []): bool
    {
        if (! in_array($condition, self::WHITELIST, true)) {
            return false;
        }

        return match ($condition) {
            'all_items_generated' => $this->allItemsMatch($context, $payload, 'generated'),
            'all_items_reviewed' => $this->allItemsMatch($context, $payload, 'reviewed'),
            'all_items_approved' => $this->allItemsMatch($context, $payload, 'approved'),
            'schedule_reached' => $this->scheduleReached($context, $payload),
            'operation_completed' => $this->operationCompleted($context, $payload),
            'site_healthy' => $this->siteHealthy($context),
            'quota_available' => $this->quotaAvailable($context, $payload),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function allItemsMatch(AgentExecutionContext $context, array $payload, string $targetStatus): bool
    {
        $projectRef = (string) ($payload['project_ref'] ?? '');
        if ($projectRef === '') {
            return false;
        }

        $result = $this->gateway->execute($context, 'content_project.list_items', [
            'project_ref' => $projectRef,
        ]);

        if (! $result->success) {
            return false;
        }

        $items = $result->data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }
            $status = (string) ($item['status'] ?? $item['semantic_status'] ?? '');
            if ($status !== $targetStatus && ! str_contains($status, $targetStatus)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scheduleReached(AgentExecutionContext $context, array $payload): bool
    {
        $projectRef = (string) ($payload['project_ref'] ?? '');
        if ($projectRef === '') {
            return false;
        }

        $result = $this->gateway->execute($context, 'content_project.get_publishing_queue', [
            'project_ref' => $projectRef,
        ]);

        if (! $result->success) {
            return false;
        }

        $due = $result->data['due_now'] ?? $result->data['due'] ?? [];
        if (! is_array($due)) {
            return false;
        }

        return count($due) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function operationCompleted(AgentExecutionContext $context, array $payload): bool
    {
        $operationRef = (string) ($payload['operation_ref'] ?? '');
        if ($operationRef === '') {
            return false;
        }

        $result = $this->gateway->execute($context, 'content_project.get_operation', [
            'operation_ref' => $operationRef,
        ]);

        if (! $result->success) {
            return false;
        }

        $status = (string) ($result->data['status'] ?? '');

        return in_array($status, ['completed', 'succeeded', 'success'], true);
    }

    private function siteHealthy(AgentExecutionContext $context): bool
    {
        $result = $this->gateway->execute($context, 'content_project.get_site_health', []);

        return $result->success && (($result->data['healthy'] ?? false) === true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function quotaAvailable(AgentExecutionContext $context, array $payload): bool
    {
        $result = $this->gateway->execute($context, 'content_project.get_status', [
            'project_ref' => (string) ($payload['project_ref'] ?? ''),
        ]);

        if (! $result->success) {
            return true;
        }

        return ($result->data['quota_exceeded'] ?? false) !== true;
    }
}
