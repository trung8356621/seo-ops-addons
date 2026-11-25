<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Services\AiErrorPresenter;
use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationCategory;
use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationSource;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

final class AiErrorPresenterTest extends TestCase
{
    private AiErrorPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new AiErrorPresenter();
    }

    public function test_openrouter_relay_400_is_provider_relay(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::TransientProvider->value,
                'http_status' => 400,
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'connection_name' => 'new-ops',
                'error' => 'Provider API error (400): Request path cannot be relayed',
            ]],
        );

        $this->assertRelayPresentation($exception);
    }

    public function test_openrouter_relay_via_fallback_reason_field(): void
    {
        // Live router stores classifier safeMessage on fallback_reason, not error.
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::TransientProvider->value,
                'http_status' => 400,
                'provider' => 'openrouter',
                'model' => 'baidu/ernie-5.5-flash-preview:free',
                'fallback_reason' => 'Provider relay path unavailable.',
                'provider_error_code' => '400',
                'error' => null,
            ]],
        );

        $this->assertRelayPresentation($exception);
    }

    private function assertRelayPresentation(AiRoutesExhaustedException $exception): void
    {

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.meta_description_suggestion',
        ]);

        self::assertSame(AiErrorPresentationCategory::ProviderRelay, $presentation->category);
        self::assertSame(AiErrorPresentationSource::Provider, $presentation->source);
        self::assertSame('openrouter', $presentation->providerName);
        self::assertSame(400, $presentation->upstreamHttpStatus);
        self::assertTrue($presentation->fallbackExhausted);
        self::assertStringContainsString('Meta Description', $presentation->message);
        self::assertStringContainsString('AI Provider', $presentation->message);
        self::assertStringContainsString('relay', strtolower((string) $presentation->upstreamMessage.(string) $presentation->message));
        self::assertStringNotContainsString('sk-', $presentation->message);
        self::assertSame(AiNormalizedFailureCode::ProviderRelayBlocked->value, $presentation->errorCode);
    }

    public function test_http_400_invalid_request_not_treated_as_relay(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::RequestInvalid->value,
                'http_status' => 400,
                'provider' => 'openrouter',
                'model' => 'x',
                'error' => 'Provider API error (400): unsupported parameter temperature',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.outline.structure.generate',
        ]);

        self::assertNotSame(AiErrorPresentationCategory::ProviderRelay, $presentation->category);
        self::assertSame(AiErrorPresentationSource::Provider, $presentation->source);
        self::assertStringContainsString('Outline', $presentation->message);
    }

    public function test_http_401_is_credential_error(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::CredentialInvalid->value,
                'http_status' => 401,
                'provider' => 'openrouter',
                'model' => 'x',
                'error' => 'Unauthorized',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.outline.structure.generate',
        ]);

        self::assertSame(AiErrorPresentationCategory::InvalidCredential, $presentation->category);
        self::assertSame(AiErrorPresentationSource::Credential, $presentation->source);
        self::assertStringContainsString('API key', $presentation->message);
    }

    public function test_http_429_is_rate_limit(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 2,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::RateLimited->value,
                'http_status' => 429,
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'error' => 'rate limit',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception);

        self::assertSame(AiErrorPresentationCategory::ProviderRateLimit, $presentation->category);
        self::assertSame('deepseek', $presentation->providerName);
    }

    public function test_quota_exhausted_is_billing(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::BillingExhausted->value,
                'http_status' => 402,
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'error' => 'Payment required',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.content.generate',
        ]);

        self::assertSame(AiErrorPresentationCategory::ProviderQuota, $presentation->category);
        self::assertStringContainsString('Article Generation', $presentation->message);
    }

    public function test_timeout_is_provider_unavailable(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::TransientProvider->value,
                'http_status' => 408,
                'provider' => 'openrouter',
                'model' => 'x',
                'error' => 'timeout',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception);

        self::assertSame(AiErrorPresentationCategory::ProviderUnavailable, $presentation->category);
    }

    public function test_routes_exhausted_keeps_root_cause_and_marks_consequence(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'failure_class' => AiFailureClass::TransientProvider->value,
                'http_status' => 400,
                'provider' => 'openrouter',
                'model' => 'first-model',
                'error' => 'Request path cannot be relayed',
            ], [
                'result' => 'skipped',
                'skip_reason' => 'connection_suppressed',
                'provider' => 'openrouter',
                'model' => 'last-model',
            ]],
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.meta_description_suggestion',
        ]);

        self::assertSame(AiErrorPresentationCategory::ProviderRelay, $presentation->category);
        self::assertTrue($presentation->fallbackExhausted);
        self::assertSame('first-model', $presentation->modelName);
        self::assertNotSame('last-model', $presentation->modelName);
        $hasConsequence = false;
        foreach ($presentation->detailLines as $line) {
            if (str_contains($line, 'hết tuyến AI')) {
                $hasConsequence = true;
                break;
            }
        }
        self::assertTrue($hasConsequence);
    }

    public function test_laravel_405_is_internal_api_error(): void
    {
        $exception = new MethodNotAllowedHttpException(
            ['POST'],
            'The GET method is not supported for route api/seo/prompt-hooks/x/execute. Supported methods: POST.',
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.meta_description_suggestion',
        ]);

        self::assertSame(AiErrorPresentationCategory::HttpRouteMismatch, $presentation->category);
        self::assertSame(AiErrorPresentationSource::Internal, $presentation->source);
        self::assertStringContainsString('SEO-OPS', $presentation->message);
        self::assertFalse($presentation->retryable);
    }

    public function test_output_invalid_is_validation(): void
    {
        $exception = new InvalidOutput('schema mismatch');

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.vocabulary.generate',
        ]);

        self::assertSame(AiErrorPresentationCategory::OutputValidation, $presentation->category);
        self::assertStringContainsString('Vocabulary', $presentation->message);
    }

    public function test_unknown_exception_does_not_guess_provider(): void
    {
        $exception = new \RuntimeException('unexpected boom');

        $presentation = $this->presenter->presentFromThrowable($exception);

        self::assertContains($presentation->category, [
            AiErrorPresentationCategory::InternalFailure,
            AiErrorPresentationCategory::Unknown,
        ]);
        self::assertNotSame(AiErrorPresentationSource::Provider, $presentation->source);
        self::assertNull($presentation->providerName);
    }

    public function test_prompt_hook_input_invalid(): void
    {
        $exception = new PromptHookException(
            PromptHookErrorCode::HookInputInvalid,
            'title required',
        );

        $presentation = $this->presenter->presentFromThrowable($exception, [
            'hook_key' => 'article.meta_description_suggestion',
        ]);

        self::assertSame(AiErrorPresentationCategory::PromptInputValidation, $presentation->category);
        self::assertSame(AiErrorPresentationSource::Internal, $presentation->source);
    }

    public function test_does_not_leak_api_key_material(): void
    {
        $exception = new PromptRunException(
            'auth failed Bearer sk-or-v1-secretvalue123 api_key=supersecret',
            0,
            null,
            [
                'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                'attempt_count' => 1,
                'routing_attempts' => [[
                    'result' => 'failed',
                    'failure_class' => AiFailureClass::CredentialInvalid->value,
                    'http_status' => 401,
                    'provider' => 'openrouter',
                    'model' => 'x',
                    'error' => 'Bearer sk-or-v1-secretvalue123',
                ]],
            ],
        );

        $presentation = $this->presenter->presentFromThrowable($exception);
        $blob = json_encode($presentation->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('sk-or-v1-secretvalue123', $blob);
        self::assertStringNotContainsString('supersecret', $blob);
        self::assertStringContainsString('[redacted]', $blob);
    }
}
