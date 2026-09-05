<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use PHPUnit\Framework\TestCase;
use TypeError;

final class AiProviderFailureClassifierTest extends TestCase
{
    private AiProviderFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AiProviderFailureClassifier();
    }

    public function test_402_insufficient_budget_is_recoverable_paid_scope(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Provider API error (402): This request requires more credits, or fewer max_tokens.',
            402,
        ));

        $this->assertSame(AiFailureClass::InsufficientBudgetForRequest, $decision->category);
        $this->assertSame(AiFailureScope::ConnectionPaid, $decision->scope);
        $this->assertTrue($decision->recoverable);
        $this->assertSame(AiFailureRuntimeAction::Continue, $decision->runtimeAction);
        $this->assertTrue($decision->lockConnectionPaid);
        $this->assertTrue($decision->fallbackAllowed());
    }

    public function test_401_credential_invalid_locks_connection(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('invalid api key', 401));
        $this->assertSame(AiFailureClass::CredentialInvalid, $decision->category);
        $this->assertTrue($decision->lockConnection);
        $this->assertTrue($decision->fallbackAllowed());
    }

    public function test_404_model_not_found_marks_model_unavailable(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('model not found', 404));
        $this->assertSame(AiFailureClass::ModelNotFound, $decision->category);
        $this->assertTrue($decision->markModelUnavailable);
        $this->assertTrue($decision->fallbackAllowed());
    }

    public function test_429_rate_limited_continues_with_cooldown_not_billing(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('429 rate limit', 429));
        $this->assertSame(AiFailureClass::RateLimited, $decision->category);
        $this->assertTrue($decision->applyCooldown);
        $this->assertFalse($decision->manualUnlockRequired);
        $this->assertFalse($decision->lockConnectionPaid);
        $this->assertTrue($decision->fallbackAllowed());
    }

    public function test_503_transient_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('503 unavailable', 503));
        $this->assertSame(AiFailureClass::TransientProvider, $decision->category);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertSame('provider_http', $decision->failureStage);
    }

    public function test_timeout_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('cURL error 28: Operation timed out'));
        $this->assertSame(AiFailureClass::TransientProvider, $decision->category);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertSame('transport', $decision->failureStage);
    }

    public function test_system_error_stops_immediately(): void
    {
        $decision = $this->classifier->classify(new TypeError('Cannot access offset'));
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
        $this->assertFalse($decision->recoverable);
        $this->assertSame(AiFailureRuntimeAction::Stop, $decision->runtimeAction);
        $this->assertFalse($decision->fallbackAllowed());
    }

    public function test_invalid_prompt_hook_is_system_error(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('invalid prompt hook definition'));
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
        $this->assertFalse($decision->recoverable);
    }

    public function test_malformed_json_output_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Provider output invalid: malformed JSON / truncated response',
        ));
        $this->assertSame(AiFailureClass::ProviderInvalidOutput, $decision->category);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertSame(AiFailureRuntimeAction::Continue, $decision->runtimeAction);
        $this->assertFalse($decision->affectsRuntimeHealth);
        $this->assertSame('parse', $decision->failureStage);
    }

    public function test_schema_validation_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Planner structured output invalid after repair (schema validation failed)',
        ));
        $this->assertSame(AiFailureClass::ProviderInvalidOutput, $decision->category);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertFalse($decision->affectsRuntimeHealth);
    }

    public function test_empty_output_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('Provider returned empty content.'));
        $this->assertSame(AiFailureClass::ProviderEmptyOutput, $decision->category);
        $this->assertSame(AiFailureRuntimeAction::Continue, $decision->runtimeAction);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertFalse($decision->affectsRuntimeHealth);

        $vi = $this->classifier->classify(new PromptRunException('DeepSeek không trả về nội dung.'));
        $this->assertSame(AiFailureClass::ProviderEmptyOutput, $vi->category);
        $this->assertTrue($vi->fallbackAllowed());
        $this->assertFalse($vi->affectsRuntimeHealth);
    }

    public function test_output_quality_denies_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Content quality rejected: unexpected_script',
            0,
            null,
            ['classification' => AiFailureClass::OutputQuality->value, 'retryable' => true],
        ));
        $this->assertSame(AiFailureClass::OutputQuality, $decision->category);
        $this->assertFalse($decision->fallbackAllowed());
        $this->assertFalse($decision->affectsRuntimeHealth);
    }

    public function test_refusal_allows_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('Model refused due to safety content policy'));
        $this->assertSame(AiFailureClass::ProviderRefusal, $decision->category);
        $this->assertSame(AiFailureRuntimeAction::Continue, $decision->runtimeAction);
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertFalse($decision->affectsRuntimeHealth);
    }

    public function test_context_length_exceeded_denies_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'This model\'s maximum context length was exceeded',
            400,
        ));
        $this->assertSame(AiFailureClass::ContextLimitExceeded, $decision->category);
        $this->assertFalse($decision->fallbackAllowed());
    }

    public function test_http_400_invalid_request_denies_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Provider API error (400): unsupported parameter temperature',
            400,
        ));
        $this->assertSame(AiFailureClass::RequestInvalid, $decision->category);
        $this->assertFalse($decision->fallbackAllowed());
    }

    public function test_retryable_flag_alone_does_not_allow_fallback(): void
    {
        $decision = $this->classifier->classify(new PromptRunException(
            'Business validation returned false for exact-count mismatch',
            0,
            null,
            ['retryable' => true],
        ));
        $this->assertFalse($decision->fallbackAllowed());
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
    }

    public function test_unknown_exception_denies_fallback_by_default(): void
    {
        $decision = $this->classifier->classify(new \RuntimeException('something unexpected happened'));
        $this->assertFalse($decision->fallbackAllowed());
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
    }
}
