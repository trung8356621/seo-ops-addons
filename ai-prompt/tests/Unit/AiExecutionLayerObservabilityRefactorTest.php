<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\Services\AiPrimaryFailureSelector;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiAttemptBudgetPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureCategory;
use Omnichannel\Addons\AiPrompt\Support\AiHistoryRouteDisplay;
use Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode;
use Omnichannel\Addons\AiPrompt\Support\OutputValidationContractRegistry;
use Omnichannel\Addons\Content\Support\ArticleAiHistoryPromptCentricPresenter;
use PHPUnit\Framework\TestCase;

final class AiExecutionLayerObservabilityRefactorTest extends TestCase
{
    public function test_attempt_budget_does_not_reserve_one_slot_per_paid_route(): void
    {
        $policy = new AiAttemptBudgetPolicy();
        $budget = $policy->resolve(
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            genuinelyEligiblePaidFallbackExists: true,
            mode: AiExecutionRoutingMode::FreeFirstWithPaidFallback,
        );

        $this->assertSame(1, $budget['reserved_paid_slots']);
        $this->assertSame(3, $budget['free_budget']);
        $this->assertGreaterThan(0, $budget['free_budget']);
    }

    public function test_free_only_never_reserves_paid_slots(): void
    {
        $budget = (new AiAttemptBudgetPolicy())->resolve(
            6,
            3,
            true,
            AiExecutionRoutingMode::FreeOnly,
        );
        $this->assertSame(0, $budget['reserved_paid_slots']);
        $this->assertSame(3, $budget['free_budget']);
    }

    public function test_economy_default_is_free_first_not_free_only(): void
    {
        $mode = (new AiRoutingContextResolver())->resolveMode(new AiRoutingContext(
            costPolicy: AiCostPolicy::Default,
        ));
        $this->assertSame(AiExecutionRoutingMode::FreeFirstWithPaidFallback, $mode);
    }

    public function test_explicit_free_only_cost_policy_maps_correctly(): void
    {
        $mode = (new AiRoutingContextResolver())->resolveMode(new AiRoutingContext(
            costPolicy: AiCostPolicy::FreeOnly,
        ));
        $this->assertSame(AiExecutionRoutingMode::FreeOnly, $mode);
    }

    public function test_article_content_contract_allows_min_words_outline_does_not(): void
    {
        $registry = new OutputValidationContractRegistry();
        $content = $registry->resolve('article.content.generate');
        $outline = $registry->resolve('article.outline.generate');
        $vocab = $registry->resolve('article.vocabulary.generate');

        $this->assertTrue($content['allows_article_min_words']);
        $this->assertFalse($outline['allows_article_min_words']);
        $this->assertFalse($vocab['allows_article_min_words']);
        $this->assertSame('article.content.generate', $content['contract']);
        $this->assertStringContainsString('outline', $outline['contract']);
    }

    public function test_zero_attempts_classified_as_routing(): void
    {
        $failure = (new AiPrimaryFailureSelector())->select(
            terminalException: null,
            routingAttempts: [
                ['result' => 'skipped', 'skip_reason' => 'connection_locked'],
            ],
            actualAttempts: 0,
        );
        $this->assertSame(AiFailureCategory::Routing, $failure->category);
        $this->assertSame(AiNormalizedFailureCode::RoutingAllCandidatesBlocked, $failure->code);
    }

    public function test_validation_failure_not_overwritten_by_routes_exhausted(): void
    {
        $exception = new OutputTruncated('too short');
        $failure = (new AiPrimaryFailureSelector())->select(
            terminalException: $exception,
            routingAttempts: [
                ['result' => 'success', 'provider' => 'openrouter', 'model' => 'x'],
            ],
            actualAttempts: 1,
            validationTrace: [
                'validation_contract' => 'article.content.generate',
                'actual_word_count' => 434,
                'minimum_acceptable_words' => 501,
                'target_article_length' => 1000,
                'validators_applied' => ['non_empty', 'min_words:501', 'target_words:1000'],
            ],
        );
        $this->assertSame(AiFailureCategory::Validation, $failure->category);
        $this->assertSame(AiNormalizedFailureCode::OutputTooShort, $failure->code);
        $this->assertStringContainsString('434', $failure->userMessage);
        $this->assertStringContainsString('501', $failure->userMessage);
    }

    public function test_provider_failures_classified_as_provider(): void
    {
        $failure = (new AiPrimaryFailureSelector())->select(
            terminalException: null,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'failure_class' => 'billing_exhausted',
                    'http_status' => 402,
                    'provider' => 'openrouter',
                    'model' => 'deepseek/deepseek-chat',
                    'connection_id' => 12,
                ],
            ],
            actualAttempts: 1,
        );
        $this->assertSame(AiFailureCategory::Provider, $failure->category);
        $this->assertSame(AiNormalizedFailureCode::ProviderBillingLimit, $failure->code);
    }

    public function test_history_groups_by_prompt_key_latest_only(): void
    {
        $groups = ArticleAiHistoryPromptCentricPresenter::regroupByPromptKey([
            [
                'run_id' => 1,
                'prompts' => [
                    [
                        'hook_key' => 'article.outline.generate',
                        'type' => 'Outline',
                        'ran_at' => '2026-09-09 08:00:00',
                        'status' => 'success',
                        'model' => 'old',
                    ],
                    [
                        'hook_key' => 'article.content.generate',
                        'type' => 'Article',
                        'ran_at' => '2026-09-09 08:10:00',
                        'status' => 'failed',
                        'routing_attempts' => [
                            ['result' => 'failed', 'provider' => 'openrouter', 'model' => 'x'],
                        ],
                    ],
                ],
            ],
            [
                'run_id' => null,
                'prompts' => [
                    [
                        'hook_key' => 'article.outline.generate',
                        'type' => 'Outline',
                        'ran_at' => '2026-09-09 09:12:00',
                        'status' => 'success',
                        'routing_attempts' => [
                            ['result' => 'success', 'provider' => 'gemini', 'model' => 'gemini-flash', 'actual_provider_model' => 'gemini-flash'],
                        ],
                    ],
                ],
            ],
        ], latestOnly: true);

        $this->assertCount(2, $groups);
        $this->assertSame('article.outline.generate', $groups[0]['prompt_key']);
        $this->assertCount(1, $groups[0]['prompts']);
        $this->assertStringContainsString('gemini-flash', (string) $groups[0]['latest_model']);
    }

    public function test_zero_attempts_display_no_model_attempted(): void
    {
        $label = AiHistoryRouteDisplay::resolveModelDisplay([], [
            ['result' => 'skipped', 'skip_reason' => 'connection_paid_locked'],
        ]);
        $this->assertStringStartsWith(AiHistoryRouteDisplay::MODEL_NO_ATTEMPT, $label);
    }

    public function test_legacy_unknown_model_becomes_routing_metadata_unavailable(): void
    {
        $label = AiHistoryRouteDisplay::resolveModelDisplay(['model' => 'Unknown model'], []);
        $this->assertSame(AiHistoryRouteDisplay::MODEL_LEGACY_UNAVAILABLE, $label);
    }
}
