<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiHealthUiPresenter;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Tests\TestCase;

final class AiHealthUiPresenterTest extends TestCase
{
    private AiHealthUiPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new AiHealthUiPresenter();
    }

    public function test_status_badge_mappings(): void
    {
        $this->assertSame('Healthy', $this->presenter->statusBadge(AiRuntimeHealthStatus::Healthy->value)['label']);
        $this->assertSame('success', $this->presenter->statusBadge(AiRuntimeHealthStatus::Healthy->value)['tone']);

        $this->assertSame('Budget limited', $this->presenter->statusBadge(AiRuntimeHealthStatus::BudgetLimited->value)['label']);
        $this->assertSame('warning', $this->presenter->statusBadge(AiRuntimeHealthStatus::BudgetLimited->value)['tone']);

        $this->assertSame('Degraded', $this->presenter->statusBadge(AiRuntimeHealthStatus::Degraded->value)['label']);
        $this->assertSame('No data', $this->presenter->statusBadge(AiRuntimeHealthStatus::NoData->value)['label']);
        $this->assertSame('neutral', $this->presenter->statusBadge(AiRuntimeHealthStatus::NoData->value)['tone']);
        $this->assertSame('Locked', $this->presenter->statusBadge(AiRuntimeHealthStatus::ConnectionLocked->value)['label']);
        $this->assertSame('Unavailable', $this->presenter->statusBadge(AiRuntimeHealthStatus::Unavailable->value)['label']);
    }

    public function test_failure_class_humanization(): void
    {
        $this->assertSame(
            'Insufficient request budget',
            $this->presenter->issuePrimary(AiFailureClass::InsufficientBudgetForRequest->value),
        );
        $this->assertSame(
            'Invalid API credentials',
            $this->presenter->issuePrimary(AiFailureClass::CredentialInvalid->value),
        );
        $this->assertSame('—', $this->presenter->issuePrimary(null));
        $this->assertSame('Custom thing', $this->presenter->issuePrimary('custom_thing'));
    }

    public function test_connection_budget_issue_does_not_look_like_model_unavailable(): void
    {
        $rows = $this->presenter->presentModels([
            [
                'model_id' => 9,
                'model_name' => 'Anthropic: Claude Sonnet 4.6',
                'raw_model_name' => 'anthropic/claude-sonnet-4.6',
                'provider' => 'openrouter',
                'connection_name' => 'OpenRouter',
                'area_label' => 'Long-form Text',
                'health_status' => AiRuntimeHealthStatus::Degraded->value,
                'success_count' => 0,
                'failure_count' => 1,
                'consecutive_failures' => 1,
                'last_failure_class' => AiFailureClass::InsufficientBudgetForRequest->value,
                'last_error_code' => '402',
                'last_success_at' => null,
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_connection_budget_issue']);
        $this->assertSame('Degraded', $rows[0]['status_badge']['label']);
        $this->assertSame('Affected by connection', $rows[0]['issue_primary']);
        $this->assertStringContainsString('OpenRouter', (string) $rows[0]['issue_secondary']);
        $this->assertNotSame('Unavailable', $rows[0]['status_badge']['label']);
    }

    public function test_enable_paid_routes_action_label(): void
    {
        $rows = $this->presenter->presentConnections([
            [
                'connection_id' => 3,
                'connection_name' => 'OpenRouter',
                'provider' => 'openrouter',
                'health_status' => AiRuntimeHealthStatus::BudgetLimited->value,
                'success_count' => 0,
                'failure_count' => 1,
                'consecutive_failures' => 1,
                'last_failure_class' => AiFailureClass::InsufficientBudgetForRequest->value,
                'last_error_code' => '402',
                'last_success_at' => null,
                'action' => ['label' => 'Enable paid routes', 'action' => 'enable_paid_routes'],
            ],
        ]);

        $this->assertSame('enable_paid_routes', $rows[0]['action_name']);
        $this->assertSame('Enable paid routes', $rows[0]['action_label']);
        $this->assertSame('Budget limited', $rows[0]['status_badge']['label']);
        $this->assertSame('Insufficient request budget', $rows[0]['issue_primary']);
    }

    public function test_summary_aggregates_connections_and_models(): void
    {
        $summary = $this->presenter->summary(
            [
                ['health_status' => AiRuntimeHealthStatus::Healthy->value],
                ['health_status' => AiRuntimeHealthStatus::BudgetLimited->value],
                ['health_status' => AiRuntimeHealthStatus::NoData->value],
            ],
            [
                ['health_status' => AiRuntimeHealthStatus::Healthy->value],
                ['health_status' => AiRuntimeHealthStatus::Degraded->value],
            ],
        );

        $this->assertSame(2, $summary['healthy']);
        $this->assertSame(1, $summary['degraded']);
        $this->assertSame(1, $summary['issues']);
        $this->assertSame(1, $summary['no_data']);
    }

    public function test_presenter_does_not_expose_secrets(): void
    {
        $rows = $this->presenter->presentConnections([
            [
                'connection_id' => 1,
                'connection_name' => 'Gemini',
                'provider' => 'gemini',
                'health_status' => AiRuntimeHealthStatus::Healthy->value,
                'success_count' => 1,
                'failure_count' => 0,
                'consecutive_failures' => 0,
                'last_failure_class' => null,
                'last_failure_message' => 'sk-secret-should-not-surface-as-primary',
                'api_key' => 'sk-live-do-not-render',
                'action' => null,
            ],
        ]);

        $encoded = json_encode($rows[0]);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('sk-live-do-not-render', $encoded);
        $this->assertSame('—', $rows[0]['issue_primary']);
    }
}
