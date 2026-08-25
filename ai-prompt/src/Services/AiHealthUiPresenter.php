<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;

/**
 * Presentation-only helpers for AI Center → Health.
 * Does not change health recording / persistence semantics.
 */
final class AiHealthUiPresenter
{
    /**
     * @param  list<array<string, mixed>>  $connectionRows
     * @param  list<array<string, mixed>>  $modelRows
     * @return array{healthy: int, degraded: int, issues: int, no_data: int}
     */
    public function summary(array $connectionRows, array $modelRows): array
    {
        $healthy = 0;
        $degraded = 0;
        $issues = 0;
        $noData = 0;

        foreach (array_merge($connectionRows, $modelRows) as $row) {
            $status = (string) ($row['health_status'] ?? AiRuntimeHealthStatus::NoData->value);
            match ($status) {
                AiRuntimeHealthStatus::Healthy->value => $healthy++,
                AiRuntimeHealthStatus::Degraded->value => $degraded++,
                AiRuntimeHealthStatus::BudgetLimited->value,
                AiRuntimeHealthStatus::ConnectionLocked->value,
                AiRuntimeHealthStatus::Unavailable->value => $issues++,
                default => $noData++,
            };
        }

        return [
            'healthy' => $healthy,
            'degraded' => $degraded,
            'issues' => $issues,
            'no_data' => $noData,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentConnections(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');
            $name = (string) ($row['connection_name'] ?? '');
            $status = (string) ($row['health_status'] ?? AiRuntimeHealthStatus::NoData->value);
            $failureClass = is_string($row['last_failure_class'] ?? null) ? (string) $row['last_failure_class'] : null;
            $errorCode = is_string($row['last_error_code'] ?? null) ? (string) $row['last_error_code'] : null;
            $action = is_array($row['action'] ?? null) ? $row['action'] : null;
            $shortCode = AiConnectionShortCode::builtin($provider) ?? '';
            $safe = $this->publicRowFields($row);

            $out[] = array_merge($safe, [
                'display_name' => $this->titleCaseName($name),
                'provider_label' => $provider !== '' ? ApiConnectionProviders::label($provider) : '',
                'provider_key' => $provider,
                'short_code' => $shortCode,
                'badge_variant' => $this->badgeVariantForId((int) ($row['connection_id'] ?? 0)),
                'status_badge' => $this->statusBadge($status),
                'issue_primary' => $this->issuePrimary($failureClass),
                'issue_secondary' => $this->connectionIssueSecondary($status, $errorCode, $failureClass),
                'last_success_label' => $this->formatLastSuccess($row['last_success_at'] ?? null, $row['last_success_at_raw'] ?? null),
                'action_label' => $this->actionLabel($action),
                'action_name' => is_array($action) ? (string) ($action['action'] ?? '') : '',
                'has_metrics' => $status !== AiRuntimeHealthStatus::NoData->value,
            ]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentModels(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $status = (string) ($row['health_status'] ?? AiRuntimeHealthStatus::NoData->value);
            $failureClass = is_string($row['last_failure_class'] ?? null) ? (string) $row['last_failure_class'] : null;
            $errorCode = is_string($row['last_error_code'] ?? null) ? (string) $row['last_error_code'] : null;
            $provider = (string) ($row['provider'] ?? '');
            $connectionBudget = $this->isConnectionBudgetIssue($failureClass);
            $shortCode = AiConnectionShortCode::builtin($provider) ?? '';
            $connectionName = (string) ($row['connection_name'] ?? '');
            $safe = $this->publicRowFields($row);

            $out[] = array_merge($safe, [
                'provider_label' => $provider !== '' ? ApiConnectionProviders::label($provider) : '',
                'provider_key' => $provider,
                'short_code' => $shortCode,
                'badge_variant' => $this->badgeVariantForId((int) ($row['model_id'] ?? 0)),
                'status_badge' => $this->statusBadge($status),
                'issue_primary' => $connectionBudget
                    ? (string) __('seo-content-ai::filament.ai_center.issue_connection_budget')
                    : $this->issuePrimary($failureClass),
                'issue_secondary' => $connectionBudget
                    ? ($connectionName !== ''
                        ? (string) __('seo-content-ai::filament.ai_center.issue_connection_budget_named', ['name' => $this->titleCaseName($connectionName)])
                        : $this->issuePrimary($failureClass))
                    : $this->modelIssueSecondary($errorCode, $failureClass),
                'is_connection_budget_issue' => $connectionBudget,
                'last_success_label' => $this->formatLastSuccess($row['last_success_at'] ?? null, $row['last_success_at_raw'] ?? null),
                'area_label' => (string) ($row['area_label'] ?? '—'),
                'has_metrics' => $status !== AiRuntimeHealthStatus::NoData->value,
                'search_blob' => strtolower(implode(' ', array_filter([
                    (string) ($row['model_name'] ?? ''),
                    (string) ($row['raw_model_name'] ?? ''),
                    $provider,
                    (string) ($row['area_label'] ?? ''),
                    $status,
                ]))),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicRowFields(array $row): array
    {
        unset(
            $row['api_key'],
            $row['secret'],
            $row['token'],
            $row['credentials'],
            $row['password'],
            $row['last_failure_message'],
            $row['raw_provider_payload'],
            $row['provider_payload'],
        );

        return $row;
    }

    /**
     * @return array{tone: string, label: string}
     */
    public function statusBadge(string $status): array
    {
        return match ($status) {
            AiRuntimeHealthStatus::Healthy->value => [
                'tone' => 'success',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_healthy'),
            ],
            AiRuntimeHealthStatus::Degraded->value => [
                'tone' => 'warning',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_degraded'),
            ],
            AiRuntimeHealthStatus::BudgetLimited->value => [
                'tone' => 'warning',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_budget_limited'),
            ],
            AiRuntimeHealthStatus::ConnectionLocked->value => [
                'tone' => 'danger',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_locked'),
            ],
            AiRuntimeHealthStatus::Unavailable->value => [
                'tone' => 'danger',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_unavailable'),
            ],
            default => [
                'tone' => 'neutral',
                'label' => (string) __('seo-content-ai::filament.ai_center.health_status_no_data'),
            ],
        };
    }

    public function issuePrimary(?string $failureClass): string
    {
        if ($failureClass === null || $failureClass === '') {
            return '—';
        }

        $key = 'seo-content-ai::filament.ai_center.failure_class_'.$failureClass;
        $translated = __($key);
        if ($translated !== $key) {
            return (string) $translated;
        }

        return $this->prettifySnake($failureClass);
    }

    /**
     * @param  array{label?: string, action?: string}|null  $action
     */
    private function actionLabel(?array $action): string
    {
        if ($action === null) {
            return '';
        }

        return match ((string) ($action['action'] ?? '')) {
            'unlock_connection' => (string) __('seo-content-ai::filament.ai_center.action_enable_connection'),
            'enable_paid_routes' => (string) __('seo-content-ai::filament.ai_center.action_enable_paid_routes'),
            default => (string) ($action['label'] ?? ''),
        };
    }

    private function connectionIssueSecondary(string $status, ?string $errorCode, ?string $failureClass): string
    {
        if ($status === AiRuntimeHealthStatus::BudgetLimited->value) {
            return (string) __('seo-content-ai::filament.ai_center.budget_limited_help');
        }
        if ($status === AiRuntimeHealthStatus::ConnectionLocked->value) {
            return (string) __('seo-content-ai::filament.ai_center.connection_locked_help');
        }
        if ($errorCode !== null && $errorCode !== '' && ctype_digit($errorCode)) {
            return 'HTTP '.$errorCode;
        }
        if ($failureClass !== null && $failureClass !== '' && $this->issuePrimary($failureClass) === '—') {
            return '';
        }

        return '';
    }

    private function modelIssueSecondary(?string $errorCode, ?string $failureClass): string
    {
        if ($errorCode !== null && $errorCode !== '' && ctype_digit($errorCode)) {
            return 'HTTP '.$errorCode;
        }

        return '';
    }

    private function isConnectionBudgetIssue(?string $failureClass): bool
    {
        return in_array($failureClass, [
            AiFailureClass::InsufficientBudgetForRequest->value,
            AiFailureClass::BillingExhausted->value,
        ], true);
    }

    private function formatLastSuccess(mixed $display, mixed $raw): string
    {
        if (is_string($raw) && $raw !== '') {
            try {
                $carbon = Carbon::parse($raw);
                $relative = SystemDateTime::formatRelative($carbon);
                if (is_string($relative) && $relative !== '') {
                    return $relative;
                }

                return (string) (SystemDateTime::formatDateTime($carbon) ?? $display ?? '—');
            } catch (\Throwable) {
            }
        }

        if (is_string($display) && $display !== '') {
            return $display;
        }

        return '—';
    }

    private function badgeVariantForId(int $id): string
    {
        $safe = max(1, $id);
        $index = (int) (($safe * 2654435761) % AiConnectionPresenter::BADGE_VARIANT_COUNT);

        return 'badge-'.($index + 1);
    }

    private function titleCaseName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return $name;
        }
        if (str_contains($trimmed, ' ') || preg_match('/[A-Z]/', $trimmed) === 1) {
            return $trimmed;
        }

        return str_replace('-', ' ', ucwords(str_replace('_', ' ', $trimmed)));
    }

    private function prettifySnake(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
