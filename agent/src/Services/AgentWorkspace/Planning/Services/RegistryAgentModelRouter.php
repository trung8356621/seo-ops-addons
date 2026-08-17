<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelSelection;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use App\Models\ApiConnection;
use RuntimeException;
use Throwable;

/**
 * Routes planning tasks via AI Provider Registry — no vendor hard-code in UI/planner.
 */
final class RegistryAgentModelRouter implements AgentModelRouter
{
    /**
     * @param  list<array{
     *     provider_key: string,
     *     model: string,
     *     connection_id?: int|null,
     *     context_limit_tokens?: int,
     *     supports_structured_output?: bool,
     *     enabled?: bool
     * }>|null  $staticCandidates  Test/override catalog; null = resolve from site connection.
     */
    public function __construct(
        private readonly ?AiProviderResolver $resolver = null,
        private readonly ?AiModelRouterService $modelRouter = null,
        private readonly ?array $staticCandidates = null,
        private readonly int $defaultContextLimit = 128000,
    ) {}

    public function resolve(AgentModelRoutingContext $context): AgentModelSelection
    {
        $candidates = $this->staticCandidates ?? $this->loadCandidates($context);
        if ($candidates === []) {
            throw new RuntimeException('model_not_configured');
        }

        $preferred = $context->userSelectedModel;
        if (is_string($preferred) && $preferred !== '') {
            foreach ($candidates as $candidate) {
                if (! $this->isHealthyCandidate($candidate, $context)) {
                    continue;
                }
                if (($candidate['model'] ?? '') === $preferred) {
                    return $this->toSelection($candidate, 'user_selected', false);
                }
            }
            if (! $context->allowFallback) {
                throw new RuntimeException('model_unavailable');
            }
        }

        foreach ($candidates as $candidate) {
            if (! $this->isHealthyCandidate($candidate, $context)) {
                continue;
            }

            return $this->toSelection(
                $candidate,
                'task:'.$context->taskType,
                $preferred !== null && $preferred !== '',
            );
        }

        throw new RuntimeException('model_unavailable');
    }

    /**
     * @return list<array{
     *     provider_key: string,
     *     model: string,
     *     connection_id?: int|null,
     *     context_limit_tokens?: int,
     *     supports_structured_output?: bool,
     *     enabled?: bool
     * }>
     */
    private function loadCandidates(AgentModelRoutingContext $context): array
    {
        $connectionId = $context->connectionId;
        if ($connectionId === null || $connectionId <= 0) {
            return [];
        }

        try {
            if (! class_exists(ApiConnection::class)) {
                return [];
            }
            $connection = ApiConnection::query()->find($connectionId);
            if ($connection === null) {
                return [];
            }
            $providerKey = (string) ($connection->provider ?? '');
            if ($providerKey === '') {
                return [];
            }

            $model = null;
            $router = $this->modelRouter;
            if ($router === null && function_exists('app')) {
                try {
                    $router = app(AiModelRouterService::class);
                } catch (Throwable) {
                    $router = null;
                }
            }
            if ($router instanceof AiModelRouterService) {
                $profile = $this->categoryForTask($context->taskType);
                try {
                    $legacy = $connection;
                    $routed = $router->resolve($profile, new AiRoutingContext(
                        userId: (int) ($connection->user_id ?? 0),
                        legacyConnection: $legacy,
                        allowLegacyFallback: true,
                    ));

                    return [[
                        'provider_key' => $routed->provider,
                        'model' => $routed->model,
                        'connection_id' => (int) $routed->connection->id,
                        'context_limit_tokens' => $this->defaultContextLimit,
                        'supports_structured_output' => in_array('structured_output', $routed->capabilities, true),
                        'enabled' => true,
                    ]];
                } catch (Throwable) {
                    // Fall through to connection-scoped model list.
                }

                $active = $router->getActiveModel($connectionId, $profile)
                    ?? $router->getActiveModel($connectionId, 'default');
                if ($active !== null) {
                    $model = (string) ($active->raw_model_name ?? '');
                    $caps = is_array($active->capabilities ?? null) ? $active->capabilities : [];
                    $limit = (int) ($caps['context_limit_tokens'] ?? $this->defaultContextLimit);
                    $structured = (bool) ($caps['structured_output'] ?? true);

                    return [[
                        'provider_key' => $providerKey,
                        'model' => $model,
                        'connection_id' => $connectionId,
                        'context_limit_tokens' => $limit > 0 ? $limit : $this->defaultContextLimit,
                        'supports_structured_output' => $structured,
                        'enabled' => true,
                    ]];
                }
            }

            return [[
                'provider_key' => $providerKey,
                'model' => (string) ($connection->default_model ?? $connection->model ?? ''),
                'connection_id' => $connectionId,
                'context_limit_tokens' => $this->defaultContextLimit,
                'supports_structured_output' => true,
                'enabled' => true,
            ]];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array{
     *     provider_key: string,
     *     model: string,
     *     connection_id?: int|null,
     *     context_limit_tokens?: int,
     *     supports_structured_output?: bool,
     *     enabled?: bool
     * }  $candidate
     */
    private function isHealthyCandidate(array $candidate, AgentModelRoutingContext $context): bool
    {
        if (($candidate['enabled'] ?? true) === false) {
            return false;
        }
        $providerKey = (string) ($candidate['provider_key'] ?? '');
        $model = (string) ($candidate['model'] ?? '');
        if ($providerKey === '' || $model === '') {
            return false;
        }
        if ($context->requiresStructuredOutput && ($candidate['supports_structured_output'] ?? true) === false) {
            return false;
        }

        $limit = (int) ($candidate['context_limit_tokens'] ?? $this->defaultContextLimit);
        if ($context->estimatedInputTokens > 0 && $context->estimatedInputTokens > $limit) {
            return false;
        }

        $resolver = $this->resolver;
        if ($resolver === null) {
            return true; // pure unit path with static candidates
        }

        try {
            $provider = $resolver->resolveText($providerKey);
            if (! $provider->supportsModel($model)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{
     *     provider_key: string,
     *     model: string,
     *     connection_id?: int|null,
     *     context_limit_tokens?: int,
     *     supports_structured_output?: bool
     * }  $candidate
     */
    private function toSelection(array $candidate, string $reason, bool $fallbackUsed): AgentModelSelection
    {
        return new AgentModelSelection(
            providerKey: (string) $candidate['provider_key'],
            model: (string) $candidate['model'],
            routingReason: $reason,
            fallbackUsed: $fallbackUsed,
            contextLimitTokens: (int) ($candidate['context_limit_tokens'] ?? $this->defaultContextLimit),
            supportsStructuredOutput: (bool) ($candidate['supports_structured_output'] ?? true),
            connectionId: isset($candidate['connection_id']) ? (int) $candidate['connection_id'] : null,
        );
    }

    private function categoryForTask(string $taskType): string
    {
        return match ($taskType) {
            'intent_classification', 'clarification', 'conversation_summary' => 'fast',
            'plan_generation', 'plan_repair', 'assistant_answer' => 'text.reasoning',
            default => 'text.fast',
        };
    }
}
