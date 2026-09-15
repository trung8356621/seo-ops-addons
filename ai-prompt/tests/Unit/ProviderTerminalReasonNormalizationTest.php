<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderUsageNormalizer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReasonNormalizer;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use PHPUnit\Framework\TestCase;

final class ProviderTerminalReasonNormalizationTest extends TestCase
{
    public function test_openai_length_and_gemini_max_tokens_and_anthropic_max_tokens_normalize_to_output_truncated(): void
    {
        $normalizer = new AiProviderTerminalReasonNormalizer;

        self::assertSame(
            AiProviderTerminalReason::OutputTruncated,
            $normalizer->normalizeFromUsage(['finish_reason' => 'length']),
        );
        self::assertSame(
            AiProviderTerminalReason::OutputTruncated,
            $normalizer->normalizeFromUsage(['finishReason' => 'MAX_TOKENS']),
        );
        self::assertSame(
            AiProviderTerminalReason::OutputTruncated,
            $normalizer->normalizeFromUsage(['stop_reason' => 'max_tokens']),
        );
        self::assertSame(
            AiProviderTerminalReason::Completed,
            $normalizer->normalizeFromUsage(['finish_reason' => 'stop']),
        );
        self::assertSame(
            AiProviderTerminalReason::Completed,
            $normalizer->normalizeFromUsage(['stop_reason' => 'end_turn']),
        );
        self::assertSame(
            AiProviderTerminalReason::OutputTruncated,
            $normalizer->normalizeFromUsage([
                'response_status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
            ]),
        );
    }

    public function test_http_402_and_429_map_to_credit_and_rate_codes(): void
    {
        $normalizer = new AiProviderTerminalReasonNormalizer;

        self::assertSame(
            AiProviderTerminalReason::InsufficientCredit,
            $normalizer->normalizeFromHttpFailure(402, 'This request requires more credits'),
        );
        self::assertSame(
            AiProviderTerminalReason::RateLimited,
            $normalizer->normalizeFromHttpFailure(429, 'Rate limit exceeded'),
        );
        self::assertSame(
            AiProviderTerminalReason::QuotaLimited,
            $normalizer->normalizeFromHttpFailure(429, 'quota exceeded for organization'),
        );
    }

    public function test_length_stop_is_output_truncated_not_mere_short_message(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition();

        $text = trim(str_repeat('word ', 1500));
        try {
            $pipeline->process($def, [
                'text' => $text,
                'finish_reason' => 'MAX_TOKENS',
            ], null, ['article_length' => 2000]);
            self::fail('Expected OutputTruncated');
        } catch (OutputTruncated $exception) {
            self::assertSame(AiProviderTerminalReason::OutputTruncated, $exception->terminalReason);
            self::assertStringContainsString('OUTPUT_TRUNCATED', $exception->getMessage());
            self::assertStringContainsString('output_truncated', $exception->getMessage());
            self::assertStringNotContainsString('shorter than minimum', $exception->getMessage());
        }
    }

    public function test_usage_normalizer_persists_terminal_reason_for_deepseek_finish_reason(): void
    {
        $costEstimator = new class implements \Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptCostEstimator
        {
            public function estimate(array $usage): ?float
            {
                return null;
            }
        };
        $response = (new PromptProviderUsageNormalizer($costEstimator))->normalize(
            text: 'hello',
            usage: [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'finish_reason' => 'length',
            ],
            provider: 'deepseek',
            model: 'deepseek-chat',
        );

        self::assertTrue($response->truncated);
        self::assertSame('length', $response->finishReason);
        self::assertSame(AiProviderTerminalReason::OutputTruncated->value, $response->terminalReason);
        self::assertSame(
            AiProviderTerminalReason::OutputTruncated->value,
            $response->toPipelineArray()['provider_terminal_reason'],
        );
    }

    public function test_output_truncated_continues_routing_as_infrastructure_failover(): void
    {
        $decision = (new AiProviderFailureClassifier)->classify(
            new OutputTruncated(
                'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                AiProviderTerminalReason::OutputTruncated,
                'length',
            ),
        );

        self::assertSame(AiFailureClass::ProviderInvalidOutput, $decision->category);
        self::assertTrue($decision->shouldContinueRouting());
        self::assertFalse($decision->affectsRuntimeHealth);
        self::assertFalse($decision->lockConnectionPaid);
        self::assertSame('OUTPUT_TRUNCATED', $decision->errorCode);
        self::assertStringContainsString('paid physical route', $decision->safeMessage);
    }

    public function test_is_provider_length_truncation_accepts_max_tokens_aliases(): void
    {
        self::assertTrue(ArticleGenerationLengthValidator::isProviderLengthTruncation('MAX_TOKENS'));
        self::assertTrue(ArticleGenerationLengthValidator::isProviderLengthTruncation('max_tokens'));
        self::assertTrue(ArticleGenerationLengthValidator::isProviderLengthTruncation('length'));
        self::assertFalse(ArticleGenerationLengthValidator::isProviderLengthTruncation('stop'));
        self::assertFalse(ArticleGenerationLengthValidator::isProviderLengthTruncation('end_turn'));
    }

    public function test_request_budget_402_does_not_globally_lock_paid_lane(): void
    {
        $classifier = new AiProviderFailureClassifier;
        $requestScoped = $classifier->classify(new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
            'Provider API error (402): This request requires more credits, or fewer max_tokens.',
            402,
        ));
        self::assertSame(AiFailureClass::InsufficientBudgetForRequest, $requestScoped->category);
        self::assertFalse($requestScoped->lockConnectionPaid);

        $exhausted = $classifier->classify(new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
            'Provider API error (402): insufficient credits / zero balance',
            402,
        ));
        self::assertSame(AiFailureClass::BillingExhausted, $exhausted->category);
        self::assertTrue($exhausted->lockConnectionPaid);
    }

    private function markdownWordsDefinition(): PromptHookDefinition
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );

        return $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.content.generate',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => [
                'type' => 'markdown',
                'validation' => [
                    'not_empty' => true,
                    'length_unit' => 'words',
                    'min_length' => 300,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);
    }
}
