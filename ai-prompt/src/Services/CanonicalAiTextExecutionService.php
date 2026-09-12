<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\GeminiGenerateContentClient;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use RuntimeException;

/**
 * Stateless compiled-text execution through canonical AI Center routing + budget preflight.
 *
 * Use for atomic utilities that do not need a persisted SeoPrompt (e.g. seeding.comment_generate).
 * Does not bypass {@see AiOutboundBudgetGate}; never sets allow_unverified_outbound.
 */
final class CanonicalAiTextExecutionService
{
    public function __construct(
        private readonly AiModelRouterService $router,
        private readonly AiProviderResolver $aiProviders,
        private readonly GeminiGenerateContentClient $geminiClient,
        private readonly DeepSeekChatClient $deepSeekClient,
        private readonly ClaudeMessagesClient $claudeClient,
        private readonly OpenAiCompatibleProtocolAdapter $openAiCompatible,
        private readonly ?PromptBudgetPreflightService $budgetPreflight = null,
    ) {}

    private function budgetPreflight(): PromptBudgetPreflightService
    {
        if ($this->budgetPreflight instanceof PromptBudgetPreflightService) {
            return $this->budgetPreflight;
        }

        return function_exists('app') && app()->bound(PromptBudgetPreflightService::class)
            ? app(PromptBudgetPreflightService::class)
            : new PromptBudgetPreflightService();
    }

    /**
     * @param  array<string, mixed>  $options  quantity|count|max_output|desired_output_tokens|…
     * @return array{0: string, 1: array<string, mixed>|null, 2: RoutedAiCandidate}
     */
    public function generate(
        string $compiledPrompt,
        string $hookKey,
        ?AiExecutionProfile $profile = null,
        ?AiRoutingContext $context = null,
        array $options = [],
    ): array {
        $compiledPrompt = trim($compiledPrompt);
        if ($compiledPrompt === '') {
            throw new PromptRunException('Thiếu prompt để gọi AI.');
        }

        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            throw new PromptRunException('Thiếu hook_key cho AI text execution.');
        }

        $profile ??= AiExecutionProfile::TextFast;
        $context ??= new AiRoutingContext(
            userId: $this->resolveUserId(),
            hookKey: $hookKey,
            canonicalPromptKey: $hookKey,
            promptTaskType: 'atomic_text',
            modelArea: $profile->value,
        );

        if ($context->hookKey === null || $context->hookKey === '') {
            $context = new AiRoutingContext(
                userId: $context->userId ?? $this->resolveUserId(),
                legacyConnection: $context->legacyConnection,
                allowLegacyFallback: $context->allowLegacyFallback,
                usageModeOverride: $context->usageModeOverride,
                allowedFamilyKeys: $context->allowedFamilyKeys,
                costPolicy: $context->costPolicy,
                preferredModelId: $context->preferredModelId,
                requirePreferredModel: $context->requirePreferredModel,
                itemGenerationMode: $context->itemGenerationMode,
                hookKey: $hookKey,
                freeOnly: $context->freeOnly,
                isolationMode: $context->isolationMode,
                generationStrategy: $context->generationStrategy,
                canonicalPromptKey: $context->canonicalPromptKey ?? $hookKey,
                promptTaskType: $context->promptTaskType ?? 'atomic_text',
                modelArea: $context->modelArea ?? $profile->value,
                routingMode: $context->routingMode,
                maxAiAttempts: $context->maxAiAttempts,
                maxFreeAttempts: $context->maxFreeAttempts,
                routingDecisionSource: $context->routingDecisionSource,
                correlationId: $context->correlationId,
                workflowRunId: $context->workflowRunId,
                projectItemId: $context->projectItemId,
                workflowNodeId: $context->workflowNodeId,
                retryAttempt: $context->retryAttempt,
            );
        }

        try {
            [$output, $usage, $candidate, $fallbackCount, $reasons, $routingAttempts] = $this->router->executeWithProfile(
                $profile->value,
                $context,
                function (RoutedAiCandidate $routed) use ($compiledPrompt, $hookKey, $options): array {
                    return $this->executeCandidate($routed, $compiledPrompt, $hookKey, $options);
                },
            );

            if (is_array($routingAttempts) && $routingAttempts !== []) {
                try {
                    app(PromptExecutionPersistence::class)->recordRoutingAttempts(
                        attempts: $routingAttempts,
                        context: [
                            'canonical_prompt_key' => $context->canonicalPromptKey ?? $hookKey,
                            'hook_key' => $hookKey,
                            'stage' => $context->promptTaskType ?? 'atomic_text',
                            'usage' => is_array($usage) ? $usage : null,
                        ]
                    );
                } catch (\Throwable) {}
            }

            return [$output, is_array($usage) ? $usage : null, $candidate];
        } catch (\Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException $e) {
            if ($e->routingAttempts !== []) {
                try {
                    app(PromptExecutionPersistence::class)->recordRoutingAttempts(
                        attempts: $e->routingAttempts,
                        context: [
                            'canonical_prompt_key' => $context->canonicalPromptKey ?? $hookKey,
                            'hook_key' => $hookKey,
                            'stage' => $context->promptTaskType ?? 'atomic_text',
                            'usage' => null,
                        ]
                    );
                } catch (\Throwable) {}
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function executeCandidate(
        RoutedAiCandidate $routed,
        string $compiledPrompt,
        string $hookKey,
        array $options,
    ): array {
        $preflight = $this->budgetPreflight();
        $capability = $preflight->capabilities()->resolve($routed);
        $strategy = $preflight->strategies()->forHook($hookKey);

        $quantity = max(0, (int) ($options['quantity'] ?? $options['count'] ?? 0));
        $maxCap = (int) ($options['max_output'] ?? 2048);
        if ($maxCap <= 0) {
            $maxCap = 2048;
        }

        $desired = (int) ($options['desired_output_tokens'] ?? 0);
        if ($desired <= 0) {
            $desired = $strategy->estimateOutputReserve([
                'quantity' => $quantity,
                'count' => $quantity,
                'batch_target' => $quantity,
                'requested_output_tokens' => $quantity > 0
                    ? min($maxCap, max(256, $quantity * 180))
                    : $maxCap,
            ], $capability);
        }
        $desired = max(64, min($maxCap, $desired));

        $planOptions = [
            'quantity' => $quantity,
            'count' => $quantity,
            'batch_target' => $quantity,
            'continuation_already_inlined' => true,
            'schema_already_inlined' => true,
            'desired_output_tokens' => $desired,
            'minimum_required_output_tokens' => max(64, (int) floor($desired * 0.35)),
        ];

        $budgetPlan = $preflight->assertSendable($routed, $compiledPrompt, $hookKey, $planOptions);

        $callOptions = array_merge($routed->options, $options, [
            'max_output' => $budgetPlan->requestedMaxOutputTokens > 0
                ? $budgetPlan->requestedMaxOutputTokens
                : $desired,
            'budget_plan_id' => $budgetPlan->planId,
            'hook_key' => $hookKey,
        ]);
        // Production path must never bypass verified budget gate.
        unset($callOptions['allow_unverified_outbound']);

        [$text, $usage] = $this->callProvider($routed->connection, $compiledPrompt, $routed->model, $callOptions);

        $usage = is_array($usage) ? $usage : [];
        $usage['budget'] = $budgetPlan->toDiagnostics();
        $usage['budget_plan_id'] = $budgetPlan->planId;
        $usage['hook_key'] = $hookKey;

        return [$text, $usage];
    }

    /**
     * Same provider dispatch shape as PromptRunnerService::callProvider (text-only).
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function callProvider(
        ApiConnection $connection,
        string $compiled,
        string $model,
        array $options,
    ): array {
        try {
            $this->aiProviders->assertTextReady((string) $connection->provider);
        } catch (RuntimeException $exception) {
            $meta = is_array($connection->metadata) ? $connection->metadata : [];
            if (! is_array($meta['provider_template'] ?? null)) {
                throw new PromptRunException($exception->getMessage());
            }
        }

        return match ($connection->provider) {
            ApiConnectionProviders::GEMINI => $this->geminiClient->generate($connection, $compiled, $model, $options),
            ApiConnectionProviders::CLAUDE => $this->claudeClient->generate($connection, $compiled, $model, $options),
            ApiConnectionProviders::DEEPSEEK => $this->deepSeekClient->generate($connection, $compiled, $model, $options),
            default => $this->openAiCompatible->generate($connection, $compiled, $model, $options),
        };
    }

    private function resolveUserId(): ?int
    {
        $id = (int) (auth()->id() ?? 0);

        return $id > 0 ? $id : null;
    }
}
