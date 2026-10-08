<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionRequest;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Decision\RoutingDecisionRejected;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\TopicGroupArticleSource;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Routing\DeterministicToolParameters;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\LocalToolRoute;
use Throwable;

final class AgentTurnResult
{
    public function __construct(
        public ?AgentResponse $response,
        public PreparedModelInput $routingInput,
        public ?PreparedModelInput $answerInput,
        public bool $answerModelCalled,
        public ?string $failureCode = null,
        public ?array $answerDiagnostics = null,
        public ?array $modelDiagnostics = null,
        public ?AgentToolConfirmationProposal $confirmationProposal = null,
        public ?array $executionTrace = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'response' => $this->response?->toArray(),
            'copy' => [
                'answer' => $this->answerInput?->exportText(),
                'routing' => $this->routingInput->exportText(),
            ],
            'answer_model_called' => $this->answerModelCalled,
        ];

        if ($this->modelDiagnostics !== null) {
            $data['model_diagnostics'] = $this->modelDiagnostics;
        }

        if ($this->answerDiagnostics !== null) {
            $data['answer_diagnostics'] = $this->answerDiagnostics;
        }

        return $data;
    }
}

final readonly class InterceptedModelCall
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public string $key,
        public PreparedModelInput $input,
        public array $state,
    ) {}
}

final readonly class AgentTurnProgress
{
    private function __construct(
        public ?AgentTurnResult $result,
        public ?InterceptedModelCall $modelCall,
        public ?AgentToolConfirmationProposal $confirmationProposal,
    ) {}

    public static function paused(InterceptedModelCall $call): self
    {
        return new self(null, $call, null);
    }

    public static function completed(AgentTurnResult $result): self
    {
        return new self($result, null, null);
    }

    public static function confirmationRequired(AgentToolConfirmationProposal $proposal): self
    {
        return new self(null, null, $proposal);
    }
}

/**
 * Routing then retrieval then answer. Copy uses the same PreparedModelInput
 * objects and does not call the answer model.
 */
class AgentTurnCoordinator
{
    public function __construct(
        private readonly AgentModelInputBuilder $inputs,
        private readonly DecisionModelGateway $decisions,
        private readonly RetrievalDecisionParser $decisionParser,
        private readonly RetrievalExecutor $retrieval,
        private readonly AnswerModelGateway $answers,
        private readonly AgentResponseParser $responses,
        private readonly ContentProjectDraftIntakeTool $draftIntake = new ContentProjectDraftIntakeTool(),
        private readonly ?LocalAgentToolRouter $localToolRouter = null,
        private readonly DeterministicToolParameters $parameters = new DeterministicToolParameters(),
        private readonly FactualAgentResponseComposer $factual = new FactualAgentResponseComposer(),
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function send(int $userId, AgentProjectScope $scope, string $message, array $history, bool $diagnostics = false): AgentTurnResult
    {
        $prepared = $this->prepare($userId, $scope, $message, $history, $diagnostics);
        $modelDiagnostics = null;
        if ($diagnostics) {
            $modelDiagnostics = [];
            if (! empty($prepared['decisionDiagnostics'])) {
                $modelDiagnostics['decision'] = $prepared['decisionDiagnostics'];
            }
        }

        if ($prepared['response'] instanceof AgentResponse) {
            return new AgentTurnResult(
                $prepared['response'],
                $prepared['routing'],
                $prepared['answer'],
                false,
                $prepared['failureCode'],
                null,
                $modelDiagnostics !== null && count($modelDiagnostics) > 0 ? $modelDiagnostics : null,
                executionTrace: $prepared['executionTrace'] ?? null,
            );
        }

        if ($prepared['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
            return new AgentTurnResult(
                null,
                $prepared['routing'],
                null,
                false,
                confirmationProposal: $prepared['confirmationProposal'],
                executionTrace: $prepared['executionTrace'] ?? null,
            );
        }

        $trace = $prepared['executionTrace'] ?? null;
        if (is_array($trace)) {
            $trace['synthesis'] = true;
            $trace['external_model_calls'] = 1;
            $trace['external_model'] = $this->assumedAnswerLabel($userId);
        }

        try {
            $raw = $this->answers->complete($userId, $prepared['answer']);
            $response = $this->responses->parse($raw, $prepared['bundle']);
        } catch (AgentResponseRejected $e) {
            $response = $this->safeResponse(
                'The answer could not be verified against the retrieved evidence, so measured values were omitted.',
                $prepared['bundle'],
            );
            $answerDiagnostics = $diagnostics ? $this->answerDiagnostics($raw ?? '', $e) : null;
        } catch (Throwable $e) {
            $response = $this->safeResponse(
                'The answer could not be verified against the retrieved evidence, so measured values were omitted.',
                $prepared['bundle'],
            );
            $answerDiagnostics = $diagnostics ? [
                'status' => 'failed',
                'failure_code' => 'answer_failed',
                'error' => $e->getMessage(),
            ] : null;
        }

        if ($diagnostics && $answerDiagnostics !== null) {
            $modelDiagnostics['answer'] = $answerDiagnostics;
        }

        return new AgentTurnResult(
            $response,
            $prepared['routing'],
            $prepared['answer'],
            true,
            $this->failureCodeFromBundle($prepared['bundle']),
            $answerDiagnostics ?? null,
            $modelDiagnostics !== null && count($modelDiagnostics) > 0 ? $modelDiagnostics : null,
            executionTrace: $trace,
        );
    }

    /**
     * Starts the production pipeline with model completion intercepted.
     * The caller persists the returned state on the normal run.
     *
     * @param list<array{role: string, content: string}> $history
     */
    public function startIntercepted(AgentProjectScope $scope, string $message, array $history): AgentTurnProgress
    {
        if ($this->localToolRouter instanceof LocalAgentToolRouter) {
            return $this->progressFromPrepared($this->prepare(0, $scope, $message, $history));
        }

        $routingInput = $this->buildRoutingInput($scope, $message, $history);
        if ($scope->isGlobal()) {
            $bundle = RetrievalBundle::unsupportedGlobal($scope);
            $answer = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text');

            return AgentTurnProgress::completed(new AgentTurnResult(
                $this->safeResponse(
                    'All Sites is selected, but a global SEO Access API is not available. Ask again inside a site project.',
                    $bundle,
                    'global_access_unsupported',
                ),
                $routingInput,
                $answer,
                false,
                'global_access_unsupported',
            ));
        }

        return AgentTurnProgress::paused(new InterceptedModelCall('decision', $routingInput, [
            'routing_input' => ['stage' => $routingInput->stage, 'messages' => $routingInput->messages],
        ]));
    }

    /** @param array<string, mixed> $state */
    public function resumeIntercepted(
        AgentProjectScope $scope,
        string $message,
        array $history,
        string $callKey,
        string $rawCompletion,
        array $state,
    ): AgentTurnProgress {
        $routingData = (array) ($state['routing_input'] ?? []);
        $routingInput = new PreparedModelInput(
            (string) ($routingData['stage'] ?? 'decision'),
            (array) ($routingData['messages'] ?? []),
        );

        if ($callKey === 'decision') {
            $processed = $this->processDecisionAndRetrieve($scope, $message, $history, $rawCompletion);
            if ($processed['error'] !== null || $processed['decision'] === null) {
                throw new RoutingDecisionRejected(
                    $processed['error'] ?? 'The routing model did not return a usable decision.',
                );
            }

            if ($processed['response'] instanceof AgentResponse) {
                return AgentTurnProgress::completed(new AgentTurnResult(
                    $processed['response'],
                    $routingInput,
                    $processed['answerInput'],
                    false,
                ));
            }

            if ($processed['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
                return AgentTurnProgress::confirmationRequired($processed['confirmationProposal']);
            }

            return AgentTurnProgress::paused(new InterceptedModelCall('answer', $processed['answerInput'], [
                'routing_input' => $state['routing_input'],
                'bundle' => $processed['bundle']->toArray(),
                'selected_response_template' => $processed['decision']->responseTemplate,
                'selected_response_language' => $processed['decision']->responseLanguage,
            ]));
        }

        if ($callKey !== 'answer' || ! isset($state['bundle']) || ! is_array($state['bundle'])) {
            throw new InvalidArgumentException('The paused model call is invalid.');
        }

        $bundle = RetrievalBundle::fromArray($state['bundle']);
        $selectedResponseTemplate = (string) ($state['selected_response_template'] ?? '');
        $selectedResponseLanguage = (string) ($state['selected_response_language'] ?? $this->legacyResponseLanguage());
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $selectedResponseTemplate, $selectedResponseLanguage);
        $response = $this->responses->parse($rawCompletion, $bundle);

        return AgentTurnProgress::completed(new AgentTurnResult(
            $response,
            $routingInput,
            $answerInput,
            true,
            $this->failureCodeFromBundle($bundle),
        ));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function copy(int $userId, AgentProjectScope $scope, string $message, array $history): AgentTurnResult
    {
        $prepared = $this->prepare($userId, $scope, $message, $history);
        if ($prepared['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
            return new AgentTurnResult(
                null,
                $prepared['routing'],
                null,
                false,
                confirmationProposal: $prepared['confirmationProposal'],
            );
        }
        $response = $prepared['response'] instanceof AgentResponse
            ? $prepared['response']
            : $this->safeResponse('Model input is ready. Copy does not call the model.', $prepared['bundle']);

        return new AgentTurnResult($response, $prepared['routing'], $prepared['answer'], false);
    }

    public function buildRoutingInput(AgentProjectScope $scope, string $message, array $history): PreparedModelInput
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message is required.');
        }

        return $this->inputs->buildRoutingInput($scope, $message, $history);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function buildAnswerInput(AgentProjectScope $scope, string $message, array $history, RetrievalBundle $bundle, string $selectedResponseTemplate, string $selectedResponseLanguage = 'en'): PreparedModelInput
    {
        return $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $selectedResponseTemplate, $selectedResponseLanguage);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{bundle: RetrievalBundle|null, answerInput: PreparedModelInput|null, decision: \Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision|null, response: AgentResponse|null, confirmationProposal: AgentToolConfirmationProposal|null, error: string|null}
     */
    public function processDecisionAndRetrieve(
        AgentProjectScope $scope,
        string $message,
        array $history,
        string $rawDecisionJson,
    ): array {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message is required.');
        }

        if ($scope->isGlobal()) {
            $bundle = RetrievalBundle::unsupportedGlobal($scope);
            $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text');

            return [
                'bundle' => $bundle,
                'answerInput' => $answerInput,
                'decision' => null,
                'response' => null,
                'confirmationProposal' => null,
                'error' => 'All Sites is selected, but a global SEO Access API is not available. Ask again inside a site project.',
            ];
        }

        try {
            $decision = $this->decisionParser->parse($rawDecisionJson);
        } catch (InvalidArgumentException $e) {
            $bundle = new RetrievalBundle($scope, [], ['routing_decision_invalid']);
            $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text');

            return [
                'bundle' => $bundle,
                'answerInput' => $answerInput,
                'decision' => null,
                'response' => null,
                'confirmationProposal' => null,
                'error' => $e->getMessage() ?: 'The routing model did not return a usable decision, so no SEO data was fetched.',
            ];
        }

        $confirmationProposal = AgentToolConfirmationProposal::fromDecision($decision, $scope);
        if ($confirmationProposal !== null) {
            return [
                'bundle' => null,
                'answerInput' => null,
                'decision' => $decision,
                'response' => null,
                'confirmationProposal' => $confirmationProposal,
                'error' => null,
            ];
        }

        $bundle = $decision->modules === []
            ? new RetrievalBundle($scope, [])
            : $this->retrieval->execute($decision, $scope);
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $decision->responseTemplate, $decision->responseLanguage);

        if (! $decision->isInScope) {
            return [
                'bundle' => $bundle,
                'answerInput' => $answerInput,
                'decision' => $decision,
                'response' => $this->outOfScopeResponse($decision->responseLanguage),
                'confirmationProposal' => null,
                'error' => null,
            ];
        }

        return [
            'bundle' => $bundle,
            'answerInput' => $answerInput,
            'decision' => $decision,
            'response' => null,
            'confirmationProposal' => null,
            'error' => null,
        ];
    }

    public function parseAnswerResult(string $rawAnswerText, RetrievalBundle $bundle): AgentResponse
    {
        return $this->responses->parse($rawAnswerText, $bundle);
    }

    /** @param list<array{role: string, content: string}> $history */
    public function answerConfirmed(
        int $userId,
        AgentProjectScope $scope,
        string $message,
        array $history,
        RetrievalBundle $bundle,
        string $responseTemplate,
        string $responseLanguage,
    ): AgentTurnResult {
        $routingInput = $this->buildRoutingInput($scope, $message, $history);
        $factual = $this->factual->compose($bundle, $message, $responseLanguage);
        $unresolved = $this->factual->unresolvedRequirements($bundle, $message);
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $responseTemplate, $responseLanguage, $unresolved);
        if ($factual instanceof AgentResponse) {
            return new AgentTurnResult(
                $factual,
                $routingInput,
                $answerInput,
                false,
                $this->failureCodeFromBundle($bundle),
                executionTrace: [
                    'router' => 'local',
                    'synthesis' => false,
                    'external_model' => null,
                    'external_model_calls' => 0,
                    'tools' => array_map(static fn (RetrievalSource $source): string => $source->name, $bundle->sources),
                ],
            );
        }

        try {
            $raw = $this->answers->complete($userId, $answerInput);
            $response = $this->responses->parse($raw, $bundle);
        } catch (Throwable) {
            $response = $this->safeResponse(
                'The answer could not be verified against the retrieved evidence, so measured values were omitted.',
                $bundle,
            );
        }

        return new AgentTurnResult(
            $response,
            $routingInput,
            $answerInput,
            true,
            $this->failureCodeFromBundle($bundle),
            executionTrace: [
                'router' => 'local',
                'synthesis' => true,
                'external_model' => $this->assumedAnswerLabel($userId),
                'external_model_calls' => 1,
                'unresolved' => $unresolved,
                'tools' => array_map(static fn (RetrievalSource $source): string => $source->name, $bundle->sources),
            ],
        );
    }

    public function composeFactual(RetrievalBundle $bundle, string $message, string $language): ?AgentResponse
    {
        return $this->factual->compose($bundle, $message, $language);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{routing: PreparedModelInput, answer: PreparedModelInput|null, bundle: RetrievalBundle|null, response: AgentResponse|null, confirmationProposal: AgentToolConfirmationProposal|null, failureCode: string|null, decisionDiagnostics?: array|null}
     */
    private function prepare(int $userId, AgentProjectScope $scope, string $message, array $history, bool $diagnostics = false): array
    {
        $routingInput = $this->buildRoutingInput($scope, $message, $history);
        if ($scope->isGlobal()) {
            $bundle = RetrievalBundle::unsupportedGlobal($scope);

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text'),
                'bundle' => $bundle,
                'response' => $this->safeResponse(
                    'All Sites is selected, but a global SEO Access API is not available. Ask again inside a site project.',
                    $bundle,
                    'global_access_unsupported',
                ),
                'failureCode' => 'global_access_unsupported',
                'decisionDiagnostics' => null,
                'confirmationProposal' => null,
            ];
        }

        $localRoute = $this->resolveLocalRoute($message);
        if ($localRoute instanceof LocalToolRoute) {
            return $this->finishFromLocalRoute($scope, $message, $history, $routingInput, $localRoute, $diagnostics);
        }

        $decisionResult = $this->decisions->decide(new DecisionRequest($userId, $routingInput));
        if (! $decisionResult->ok) {
            $bundle = new RetrievalBundle($scope, [], [$decisionResult->failureCode ?? 'decision_unavailable']);
            $decisionDiagnostics = $diagnostics ? [
                'status' => 'failed',
                'failure_code' => $decisionResult->failureCode ?? 'decision_unavailable',
                'error' => $this->decisionFailureMessage($decisionResult->failureCode),
                'raw_completion' => (new SecretRedactor())->redact($decisionResult->rawText ?? ''),
            ] : null;

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text'),
                'bundle' => $bundle,
                'response' => $this->safeResponse(
                    $this->decisionFailureMessage($decisionResult->failureCode),
                    $bundle,
                    $decisionResult->failureCode ?? 'decision_unavailable',
                ),
                'failureCode' => $decisionResult->failureCode ?? 'decision_unavailable',
                'decisionDiagnostics' => $decisionDiagnostics,
                'confirmationProposal' => null,
            ];
        }

        $processed = $this->processDecisionAndRetrieve($scope, $message, $history, $decisionResult->rawText);
        if ($processed['error'] !== null || $processed['decision'] === null) {
            $decisionDiagnostics = $diagnostics ? [
                'status' => 'rejected',
                'failure_code' => 'routing_decision_invalid',
                'parser_error' => $processed['error'] ?? 'The routing model did not return a usable decision, so no SEO data was fetched.',
                'raw_completion' => (new SecretRedactor())->redact($decisionResult->rawText ?? ''),
            ] : null;

            return [
                'routing' => $routingInput,
                'answer' => $processed['answerInput'],
                'bundle' => $processed['bundle'],
                'response' => $this->safeResponse(
                    'The routing model did not return a usable decision, so no SEO data was fetched.',
                    $processed['bundle'],
                    'routing_decision_invalid',
                ),
                'failureCode' => 'routing_decision_invalid',
                'decisionDiagnostics' => $decisionDiagnostics,
                'confirmationProposal' => null,
            ];
        }

        if ($processed['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
            return [
                'routing' => $routingInput,
                'answer' => null,
                'bundle' => null,
                'response' => null,
                'confirmationProposal' => $processed['confirmationProposal'],
                'failureCode' => null,
                'decisionDiagnostics' => null,
            ];
        }

        if ($processed['response'] instanceof AgentResponse) {
            return [
                'routing' => $routingInput,
                'answer' => $processed['answerInput'],
                'bundle' => $processed['bundle'],
                'response' => $processed['response'],
                'failureCode' => null,
                'decisionDiagnostics' => null,
                'confirmationProposal' => null,
            ];
        }

        if (! $this->draftIntake->isConnected()) {
            // Write stays unwired. The answer instructions already forbid inventing the call.
        }

        return [
            'routing' => $routingInput,
            'answer' => $processed['answerInput'],
            'bundle' => $processed['bundle'],
            'response' => null,
            'failureCode' => $this->failureCodeFromBundle($processed['bundle']),
            'decisionDiagnostics' => null,
            'confirmationProposal' => null,
        ];
    }

    private function resolveLocalRoute(string $message): ?LocalToolRoute
    {
        $router = $this->localToolRouter;
        if ($router instanceof LocalAgentToolRouter) {
            return $router->route($message);
        }

        if (config('agent-runtime.local_tool_router.enabled') !== true) {
            return null;
        }
        if (! function_exists('app') || ! app()->bound(LocalAgentToolRouter::class)) {
            return null;
        }
        $resolved = app()->make(LocalAgentToolRouter::class);

        return $resolved instanceof LocalAgentToolRouter ? $resolved->route($message) : null;
    }

    /**
     * Local routing does not call the decision model. Ambiguous and rejected
     * results stay unresolved instead of falling back to JEV.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return array{routing: PreparedModelInput, answer: PreparedModelInput|null, bundle: RetrievalBundle|null, response: AgentResponse|null, confirmationProposal: AgentToolConfirmationProposal|null, failureCode: string|null, decisionDiagnostics?: array|null}
     */
    private function finishFromLocalRoute(
        AgentProjectScope $scope,
        string $message,
        array $history,
        PreparedModelInput $routingInput,
        LocalToolRoute $route,
        bool $diagnostics,
    ): array {
        $extracted = $this->parameters->extract($message);
        $trace = $this->executionTrace($route, $extracted['parameters'], []);
        if ($route->outcome === 'none') {
            $bundle = new RetrievalBundle($scope, [], ['out_of_scope']);

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text', $extracted['language']),
                'bundle' => $bundle,
                'response' => $this->outOfScopeResponse($extracted['language']),
                'failureCode' => 'out_of_scope',
                'decisionDiagnostics' => $diagnostics ? $trace : null,
                'confirmationProposal' => null,
                'executionTrace' => $trace,
            ];
        }

        if ($route->outcome !== 'confident' || $route->catalogAuthorized !== true || $route->capability === null) {
            $bundle = new RetrievalBundle($scope, [], ['local_tool_router_'.$route->outcome]);
            $text = match ($route->outcome) {
                'ambiguous' => $extracted['language'] === 'vi'
                    ? 'Yêu cầu khớp nhiều hơn một công cụ. Hãy nói rõ capability cần dùng.'
                    : 'The request matches more than one tool. Ask which capability to use.',
                'rejected' => $extracted['language'] === 'vi'
                    ? 'Capability khớp được không được phép thực thi, nên không có công cụ nào được chạy.'
                    : 'The matched capability is not available, so no tool was executed.',
                'unavailable' => $extracted['language'] === 'vi'
                    ? 'Bộ định tuyến nội bộ không phân loại được yêu cầu, nên không có công cụ nào được chạy.'
                    : 'The local tool router could not classify this request, so no tool was executed.',
                default => $extracted['language'] === 'vi'
                    ? 'Không chọn được công cụ phù hợp, nên không có công cụ nào được chạy.'
                    : 'No suitable tool was selected, so no tool was executed.',
            };

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text', $extracted['language']),
                'bundle' => $bundle,
                'response' => $this->safeResponse($text, $bundle, 'local_tool_router_'.$route->outcome),
                'failureCode' => 'local_tool_router_'.$route->outcome,
                'decisionDiagnostics' => $diagnostics ? $trace : null,
                'confirmationProposal' => null,
                'executionTrace' => $trace,
            ];
        }

        if ($extracted['clarification'] !== null) {
            $bundle = new RetrievalBundle($scope, [], ['parameters_unresolved']);

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, 'text', $extracted['language']),
                'bundle' => $bundle,
                'response' => $this->safeResponse($extracted['clarification'], $bundle, 'parameters_unresolved'),
                'failureCode' => 'parameters_unresolved',
                'decisionDiagnostics' => $diagnostics ? $trace : null,
                'confirmationProposal' => null,
                'executionTrace' => $trace,
            ];
        }

        $processed = $this->processDecisionAndRetrieve(
            $scope,
            $message,
            $history,
            $this->localDecisionJson($route, $message, $extracted),
        );
        if ($processed['error'] !== null || $processed['decision'] === null) {
            $bundle = $processed['bundle'] ?? new RetrievalBundle($scope, [], ['routing_decision_invalid']);

            return [
                'routing' => $routingInput,
                'answer' => $processed['answerInput'],
                'bundle' => $bundle,
                'response' => $this->safeResponse(
                    'The local tool router did not produce an executable capability, so no tool was executed.',
                    $bundle,
                    'routing_decision_invalid',
                ),
                'failureCode' => 'routing_decision_invalid',
                'decisionDiagnostics' => $diagnostics ? ['status' => 'rejected', 'router' => 'local'] : null,
                'confirmationProposal' => null,
                'executionTrace' => $trace,
            ];
        }

        if ($processed['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
            $trace['parameters'] = $processed['confirmationProposal']->parameters;
            $trace['capabilities'] = $processed['confirmationProposal']->capabilities;

            return [
                'routing' => $routingInput,
                'answer' => null,
                'bundle' => null,
                'response' => null,
                'confirmationProposal' => $processed['confirmationProposal'],
                'failureCode' => null,
                'decisionDiagnostics' => $diagnostics ? $trace : null,
                'executionTrace' => $trace,
            ];
        }

        $bundle = $processed['bundle'] instanceof RetrievalBundle
            ? $this->withTopicGroupSource($processed['bundle'], $message, (string) $route->capability)
            : $processed['bundle'];
        $response = $processed['response'];
        $answer = $processed['answerInput'];
        $language = $processed['decision']?->responseLanguage ?? $extracted['language'];
        if ($response === null && $bundle instanceof RetrievalBundle) {
            $response = $this->factual->compose($bundle, $message, $language);
            if ($response === null) {
                $unresolved = $this->factual->unresolvedRequirements($bundle, $message);
                $template = $processed['decision']?->responseTemplate ?? 'text';
                $answer = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $template, $language, $unresolved);
                $trace['synthesis'] = true;
                $trace['unresolved'] = $unresolved;
                $preparedTemplate = $template;
                $preparedLanguage = $language;
            }
        }
        $trace['capabilities'] = $processed['decision']?->capabilities ?? [(string) $route->capability];
        $trace['parameters'] = $processed['decision']?->parameters ?? $extracted['parameters'];
        $trace['tools'] = $bundle instanceof RetrievalBundle
            ? array_map(static fn (RetrievalSource $source): string => $source->name, $bundle->sources)
            : [];

        return [
            'routing' => $routingInput,
            'answer' => $answer,
            'bundle' => $bundle,
            'response' => $response,
            'failureCode' => $bundle instanceof RetrievalBundle ? $this->failureCodeFromBundle($bundle) : null,
            'decisionDiagnostics' => $diagnostics ? $trace : null,
            'confirmationProposal' => null,
            'executionTrace' => $trace,
            'responseTemplate' => $preparedTemplate ?? ($processed['decision']?->responseTemplate ?? 'text'),
            'responseLanguage' => $preparedLanguage ?? $language,
        ];
    }

    private function withTopicGroupSource(RetrievalBundle $bundle, string $message, string $capability): RetrievalBundle
    {
        if (! in_array($capability, ['keywords.relationship', 'articles.inventory'], true)) {
            return $bundle;
        }
        if ($bundle->scope->siteId === null || ! function_exists('app') || ! app()->bound(TopicGroupArticleSource::class)) {
            return $bundle;
        }
        $source = app()->make(TopicGroupArticleSource::class);
        if (! $source instanceof TopicGroupArticleSource) {
            return $bundle;
        }
        $data = $source->retrieve((int) $bundle->scope->siteId, $message);

        return new RetrievalBundle(
            $bundle->scope,
            [...$bundle->sources, new RetrievalSource('topic_groups', (string) ($data['status'] ?? 'ok'), 'topic-group-retrieval', $data)],
            $bundle->warnings,
        );
    }

    /**
     * @param  array{parameters: array<string, int|string>, clarification: ?string, language: string}  $extracted
     */
    private function localDecisionJson(LocalToolRoute $route, string $message, array $extracted): string
    {
        $key = (string) $route->capability;
        $listCapabilities = [
            'seo_audit.worst_articles',
            'articles.inventory',
            'keywords.landscape',
            'keywords.relationship',
            'links.internal',
            'links.external',
            'gsc.performance',
            'content_projects.read',
        ];

        return json_encode([
            'is_in_scope' => true,
            'intent' => mb_substr(trim($message), 0, 180),
            'primary_capability' => $key,
            'capabilities' => [$key],
            'parameters' => $extracted['parameters'],
            'requires_parameter_extraction' => false,
            'requires_user_confirmation' => AgentCapabilityCatalog::requiresConfirmation($key),
            'response_template' => in_array($key, $listCapabilities, true) ? 'table' : 'text',
            'response_language' => $extracted['language'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @param  list<string>  $tools
     * @return array<string, mixed>
     */
    private function executionTrace(LocalToolRoute $route, array $parameters, array $tools): array
    {
        return [
            'router' => $route->evidenceKind,
            'outcome' => $route->outcome,
            'capabilities' => $route->capability !== null ? [$route->capability] : [],
            'parameters' => $parameters,
            'tools' => $tools,
            'synthesis' => false,
            'external_model' => null,
            'external_model_calls' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function progressFromPrepared(array $prepared): AgentTurnProgress
    {
        if ($prepared['confirmationProposal'] instanceof AgentToolConfirmationProposal) {
            return AgentTurnProgress::confirmationRequired($prepared['confirmationProposal']);
        }

        $trace = $prepared['executionTrace'] ?? null;
        if ($prepared['response'] instanceof AgentResponse) {
            return AgentTurnProgress::completed(new AgentTurnResult(
                $prepared['response'],
                $prepared['routing'],
                $prepared['answer'],
                false,
                $prepared['failureCode'] ?? null,
                executionTrace: is_array($trace) ? $trace : null,
            ));
        }

        $answer = $prepared['answer'];
        $bundle = $prepared['bundle'];
        if (! $answer instanceof PreparedModelInput || ! $bundle instanceof RetrievalBundle) {
            throw new InvalidArgumentException('Local routing did not produce an answer or a result.');
        }

        return AgentTurnProgress::paused(new InterceptedModelCall('answer', $answer, [
            'routing_input' => ['stage' => $prepared['routing']->stage, 'messages' => $prepared['routing']->messages],
            'bundle' => $bundle->toArray(),
            'selected_response_template' => (string) ($prepared['responseTemplate'] ?? 'text'),
            'selected_response_language' => (string) ($prepared['responseLanguage'] ?? 'en'),
            'execution' => $trace,
        ]));
    }

    private function assumedAnswerLabel(int $userId): ?string
    {
        if ($userId <= 0 || ! function_exists('app') || ! app()->bound(AssumedModelResolver::class)) {
            return null;
        }
        $resolver = app()->make(AssumedModelResolver::class);
        if (! $resolver instanceof AssumedModelResolver) {
            return null;
        }
        $model = $resolver->resolveAnswerModel($userId);

        return $model->model ?? $model->displayName;
    }

    private function failureCodeFromBundle(RetrievalBundle $bundle): ?string
    {
        foreach ($bundle->sources as $source) {
            if ($source->status !== 'ok' && $source->reason !== null && $source->reason !== '') {
                return $source->reason;
            }
        }

        foreach ($bundle->warnings as $warning) {
            if (is_string($warning) && $warning !== '') {
                return $warning;
            }
        }

        return null;
    }

    /** @return array{status: string, parser_error: string, raw_completion: string} */
    private function answerDiagnostics(string $raw, AgentResponseRejected $error): array
    {
        return [
            'status' => 'rejected',
            'parser_error' => $error->getMessage(),
            'raw_completion' => (new SecretRedactor())->redact($raw),
        ];
    }

    private function decisionFailureMessage(?string $code): string
    {
        if ($code === 'decision_models_not_configured') {
            return 'No Decision Model is enabled in AI Settings, so this turn did not fetch SEO data.';
        }

        return 'The Decision Model did not complete, so this turn did not fetch SEO data.';
    }

    private function safeResponse(string $message, RetrievalBundle $bundle, ?string $warning = null): AgentResponse
    {
        $blocks = [['type' => 'markdown', 'text' => $message]];
        if ($warning !== null) {
            $blocks[] = ['type' => 'warning', 'text' => $warning];
        }

        return new AgentResponse(
            $message,
            $blocks,
            [],
            array_map(static fn ($source) => $source->toArray(), $bundle->sources),
        );
    }

    private function outOfScopeResponse(string $responseLanguage): AgentResponse
    {
        $message = $responseLanguage === 'vi'
            ? 'Yêu cầu này nằm ngoài phạm vi Agent SEO nội bộ. Hãy hỏi về website, nội dung, keyword, GSC, SEO Audit, Content Projects hoặc Industry Context.'
            : 'This request is outside the internal SEO Agent scope. Ask about websites, content, keywords, GSC, SEO Audit, Content Projects, or Industry Context.';

        return new AgentResponse($message, [['type' => 'markdown', 'text' => $message]], [], []);
    }

    private function legacyResponseLanguage(): string
    {
        $locale = function_exists('app') ? strtolower((string) app()->getLocale()) : 'en';

        return in_array($locale, ['vi', 'en'], true) ? $locale : 'en';
    }
}

