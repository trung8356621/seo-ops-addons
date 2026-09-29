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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'response' => $this->response->toArray(),
            'copy' => [
                'answer' => $this->answerInput->exportText(),
                'routing' => $this->routingInput->exportText(),
            ],
            'answer_model_called' => $this->answerModelCalled,
        ];
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
    public function send(int $userId, AgentProjectScope $scope, string $message, array $history): AgentTurnResult
    {
        $prepared = $this->prepare($userId, $scope, $message, $history);
        if ($prepared['response'] instanceof AgentResponse) {
            return new AgentTurnResult(
                $prepared['response'],
                $prepared['routing'],
                $prepared['answer'],
                false,
            );
        }

        try {
            $raw = $this->answers->complete($userId, $prepared['answer']);
            $response = $this->responses->parse($raw, $prepared['bundle']);
        } catch (AgentResponseRejected | Throwable) {
            $response = $this->safeResponse(
                'The answer could not be verified against the retrieved evidence, so measured values were omitted.',
                $prepared['bundle'],
            );
        }

        return new AgentTurnResult($response, $prepared['routing'], $prepared['answer'], true);
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
            $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle);

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
            $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle);

            return [
                'bundle' => $bundle,
                'answerInput' => $answerInput,
                'decision' => null,
                'error' => $e->getMessage() ?: 'The routing model did not return a usable decision, so no SEO data was fetched.',
            ];
        }

        $bundle = $this->retrieval->execute($decision, $scope);
        $answerInput = $this->inputs->buildAnswerInput($scope, $message, $history, $bundle);

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
     * @return array{routing: PreparedModelInput, answer: PreparedModelInput, bundle: RetrievalBundle, response: AgentResponse|null}
     */
    private function prepare(int $userId, AgentProjectScope $scope, string $message, array $history): array
    {
        $routingInput = $this->buildRoutingInput($scope, $message, $history);
        if ($scope->isGlobal()) {
            $bundle = RetrievalBundle::unsupportedGlobal($scope);

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle),
                'bundle' => $bundle,
                'response' => $this->safeResponse(
                    'All Sites is selected, but a global SEO Access API is not available. Ask again inside a site project.',
                    $bundle,
                    'global_access_unsupported',
                ),
            ];
        }

        $decisionResult = $this->decisions->decide(new DecisionRequest($userId, $routingInput));
        if (! $decisionResult->ok) {
            $bundle = new RetrievalBundle($scope, [], [$decisionResult->failureCode ?? 'decision_unavailable']);

            return [
                'routing' => $routingInput,
                'answer' => $this->inputs->buildAnswerInput($scope, $message, $history, $bundle),
                'bundle' => $bundle,
                'response' => $this->safeResponse(
                    $this->decisionFailureMessage($decisionResult->failureCode),
                    $bundle,
                    $decisionResult->failureCode ?? 'decision_unavailable',
                ),
            ];
        }

        $processed = $this->processDecisionAndRetrieve($scope, $message, $history, $decisionResult->rawText);
        if ($processed['error'] !== null || $processed['decision'] === null) {
            return [
                'routing' => $routingInput,
                'answer' => $processed['answerInput'],
                'bundle' => $processed['bundle'],
                'response' => $this->safeResponse(
                    'The routing model did not return a usable decision, so no SEO data was fetched.',
                    $processed['bundle'],
                    'routing_decision_invalid',
                ),
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

