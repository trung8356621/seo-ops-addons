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
    }

    public function test_401_credential_invalid_locks_connection(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('invalid api key', 401));
        $this->assertSame(AiFailureClass::CredentialInvalid, $decision->category);
        $this->assertTrue($decision->lockConnection);
    }

    public function test_404_model_not_found_marks_model_unavailable(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('model not found', 404));
        $this->assertSame(AiFailureClass::ModelNotFound, $decision->category);
        $this->assertTrue($decision->markModelUnavailable);
    }

    public function test_429_rate_limited_continues_with_cooldown(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('429 rate limit', 429));
        $this->assertSame(AiFailureClass::RateLimited, $decision->category);
        $this->assertTrue($decision->applyCooldown);
        $this->assertFalse($decision->manualUnlockRequired);
    }

    public function test_system_error_stops_immediately(): void
    {
        $decision = $this->classifier->classify(new TypeError('Cannot access offset'));
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
        $this->assertFalse($decision->recoverable);
        $this->assertSame(AiFailureRuntimeAction::Stop, $decision->runtimeAction);
    }

    public function test_invalid_prompt_hook_is_system_error(): void
    {
        $decision = $this->classifier->classify(new PromptRunException('invalid prompt hook definition'));
        $this->assertSame(AiFailureClass::SystemError, $decision->category);
        $this->assertFalse($decision->recoverable);
    }
}
