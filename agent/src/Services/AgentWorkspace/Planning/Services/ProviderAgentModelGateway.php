<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelSelection;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningOutputSanitizer;
use RuntimeException;
use Throwable;

/**
 * Adapter over AiTextProviderInterface — no vendor SDK imports.
 */
final class ProviderAgentModelGateway implements AgentModelGateway
{
    public function __construct(
        private readonly ?AiProviderResolver $resolver = null,
        private readonly ?DeterministicAgentPlanRepairer $repairer = null,
        private readonly ?AgentPlanningOutputSanitizer $outputSanitizer = null,
        private readonly ?AiTextProviderInterface $injectedProvider = null,
    ) {}

    public function plan(
        AgentPlanningRequest $request,
        AgentModelSelection $model,
        array $assembledContext,
    ): array {
        $started = microtime(true);
        $prompt = $this->buildPlanPrompt($assembledContext);

        try {
            $provider = $this->resolveProvider($model->providerKey);
            $result = $provider->generate(
                new AiTextRequest(
                    prompt: $prompt,
                    model: $model->model,
                    options: [
                        'response_format' => 'json',
                        'temperature' => 0.2,
                        'timeout' => 45,
                    ],
                ),
                new AiExecutionContext(
                    providerKey: $model->providerKey,
                    connectionId: $model->connectionId,
                    siteId: $request->context->siteId,
                    metadata: ['task' => 'agent_planning', 'planning_request_id' => $request->planningRequestId],
                ),
            );
        } catch (Throwable $e) {
            throw $this->normalizeError($e);
        }

        if (! $result->ok || trim($result->text) === '') {
            throw new RuntimeException('provider_error:'.($result->message !== '' ? $result->message : 'empty_response'));
        }

        $decoded = $this->decodeJson($result->text);
        $sanitizer = $this->outputSanitizer ?? new AgentPlanningOutputSanitizer;
        if ($this->repairer !== null) {
            $repaired = $this->repairer->repair($decoded);
            $response = $repaired['response'];
            $repairActions = $repaired['repair_actions'];
        } else {
            $sanitized = $sanitizer->sanitize($decoded);
            $response = AgentPlanningResponse::fromArray($sanitized['payload']);
            $repairActions = array_map(
                static fn (string $f): string => 'stripped:'.$f,
                $sanitized['stripped'],
            );
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        return [
            'response' => $response,
            'meta' => [
                'latency_ms' => $latency,
                'provider' => $model->providerKey,
                'model' => $result->modelUsed !== '' ? $result->modelUsed : $model->model,
                'repair_actions' => $repairActions,
                'usage' => $result->usage,
                'raw_stripped' => true,
            ],
        ];
    }

    public function summarize(
        AgentSummarizationRequest $request,
        AgentModelSelection $model,
    ): array {
        $started = microtime(true);
        $prompt = 'Summarize this Agent Workspace conversation as compact JSON with keys: '
            .'current_objective, confirmed_facts, active_refs, decisions, completed_executions, '
            .'failed_executions, open_questions, user_corrections, constraints. '
            ."No secrets. Messages:\n"
            .(string) json_encode($request->messages, JSON_UNESCAPED_UNICODE);

        try {
            $provider = $this->resolveProvider($model->providerKey);
            $result = $provider->generate(
                new AiTextRequest(prompt: $prompt, model: $model->model, options: ['response_format' => 'json']),
                new AiExecutionContext(providerKey: $model->providerKey, connectionId: $model->connectionId),
            );
        } catch (Throwable $e) {
            throw $this->normalizeError($e);
        }

        if (! $result->ok) {
            throw new RuntimeException('provider_error:'.$result->message);
        }

        $payload = $this->decodeJson($result->text);
        $text = (string) ($payload['current_objective'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE));

        return [
            'summary' => new AgentConversationSummary(
                text: mb_substr($text, 0, 4000),
                version: 0,
                untilMessageId: null,
                payload: $payload,
            ),
            'meta' => [
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'provider' => $model->providerKey,
                'model' => $result->modelUsed !== '' ? $result->modelUsed : $model->model,
            ],
        ];
    }

    private function resolveProvider(string $providerKey): AiTextProviderInterface
    {
        if ($this->injectedProvider !== null) {
            return $this->injectedProvider;
        }
        $resolver = $this->resolver;
        if ($resolver === null) {
            throw new RuntimeException('model_not_configured');
        }

        return $resolver->resolveText($providerKey);
    }

    /**
     * @param  array<string, mixed>  $assembledContext
     */
    private function buildPlanPrompt(array $assembledContext): string
    {
        $sections = $assembledContext['prompt_sections'] ?? $assembledContext;

        return "You are the Agent Workspace planner. Return ONLY one JSON object matching the planning schema.\n"
            ."Allowed types: clarification, single_intent, execution_plan, assistant_answer, unsupported.\n"
            ."Never include auto_execute, auto_confirm, run_all, command_class, handler, or secrets.\n"
            ."Context (JSON):\n"
            .(string) json_encode($sections, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        if (preg_match('/\{.*\}/s', $trimmed, $m) === 1) {
            $trimmed = $m[0];
        }
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('structured_output_invalid');
        }

        return $decoded;
    }

    private function normalizeError(Throwable $e): RuntimeException
    {
        $msg = $e->getMessage();
        if (str_contains($msg, AiProviderResolver::ERROR_NOT_CONFIGURED)
            || str_contains($msg, 'model_not_configured')) {
            return new RuntimeException('model_not_configured', 0, $e);
        }
        if (str_contains($msg, AiProviderResolver::ERROR_DISABLED)
            || str_contains($msg, AiProviderResolver::ERROR_NOT_REGISTERED)
            || str_contains($msg, 'model_unavailable')) {
            return new RuntimeException('model_unavailable', 0, $e);
        }
        if (str_contains(mb_strtolower($msg), 'timeout')) {
            return new RuntimeException('planning_timeout', 0, $e);
        }
        if (str_contains(mb_strtolower($msg), 'rate')) {
            return new RuntimeException('rate_limited', 0, $e);
        }

        return new RuntimeException('provider_error', 0, $e);
    }
}
