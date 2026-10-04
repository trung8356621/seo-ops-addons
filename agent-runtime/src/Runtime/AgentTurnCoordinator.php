<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionRequest;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Throwable;

final class AgentTurnResult
{
    public function __construct(
        public AgentResponse $response,
        public PreparedModelInput $routingInput,
        public PreparedModelInput $answerInput,
        public bool $answerModelCalled,
        public ?string $failureCode = null,
        public ?array $answerDiagnostics = null,
        public ?array $modelDiagnostics = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'response' => $this->response->toArray(),
            'copy' => [
                'answer' => $this->answerInput->exportText(),
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
    ) {}

    public static function paused(InterceptedModelCall $call): self
    {
        return new self(null, $call);
    }

    public static function completed(AgentTurnResult $result): self
    {
        return new self($result, null);
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
            );
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
                return AgentTurnProgress::completed(new AgentTurnResult(
                    $this->safeResponse(
                        'The routing model did not return a usable decision, so no SEO data was fetched.',
                        $processed['bundle'],
                        'routing_decision_invalid',
                    ),
                    $routingInput,
                    $processed['answerInput'],
                    false,
                    'routing_decision_invalid',
                ));
            }

            return AgentTurnProgress::paused(new InterceptedModelCall('answer', $processed['answerInput'], [
                'routing_input' => $state['routing_input'],
                'bundle' => $processed['bundle']->toArray(),
                'selected_response_template' => $processed['decision']->responseTemplate,
            ]));
        }

        if ($callKey !== 'answer' || ! isset($state['bundle']) || ! is_array($state['bundle'])) {
            throw new InvalidArgumentException('The paused model call is invalid.');
        }

        $bundle = RetrievalBundle::fromArray($state['bundle']);
        $selectedResponseTemplate = (string) ($state['selected_response_template'] ?? '');
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $selectedResponseTemplate);
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
    public function buildAnswerInput(AgentProjectScope $scope, string $message, array $history, RetrievalBundle $bundle, string $selectedResponseTemplate): PreparedModelInput
    {
        return $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $selectedResponseTemplate);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{bundle: RetrievalBundle, answerInput: PreparedModelInput, decision: \Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision|null, error: string|null}
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
                'error' => $e->getMessage() ?: 'The routing model did not return a usable decision, so no SEO data was fetched.',
            ];
        }

        $bundle = $this->retrieval->execute($decision, $scope);
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle, $decision->responseTemplate);

        return [
            'bundle' => $bundle,
            'answerInput' => $answerInput,
            'decision' => $decision,
            'error' => null,
        ];
    }

    public function parseAnswerResult(string $rawAnswerText, RetrievalBundle $bundle): AgentResponse
    {
        return $this->responses->parse($rawAnswerText, $bundle);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{routing: PreparedModelInput, answer: PreparedModelInput, bundle: RetrievalBundle, response: AgentResponse|null, failureCode: string|null, decisionDiagnostics?: array|null}
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
            ];
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
        ];
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
}

