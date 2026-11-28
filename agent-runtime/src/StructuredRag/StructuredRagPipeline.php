<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;

final class StructuredRagPipeline
{
    public function __construct(
        private readonly QueryUnderstandingService $understanding,
        private readonly StructuredRetrievalPlanner $planner,
        private readonly RetrievalExecutor $retrieval,
        private readonly EvidenceContextBuilder $context,
        private readonly AgentModelInputBuilder $inputs,
        private readonly FactualAgentResponseComposer $factual = new FactualAgentResponseComposer(),
    ) {}

    /** @param list<array{role: string, content: string}> $history */
    public function prepare(AgentProjectScope $scope, string $message, array $history = []): StructuredRagResult
    {
        $totalStarted = hrtime(true);
        $stageStarted = hrtime(true);
        $understanding = $this->understanding->understand($message, $history);
        $timings = ['query_understanding' => $this->elapsed($stageStarted)];

        $stageStarted = hrtime(true);
        $plan = $this->planner->plan($understanding);
        $timings['retrieval_planning'] = $this->elapsed($stageStarted);

        if (! $understanding->requiresRetrieval) {
            $timings['total'] = $this->elapsed($totalStarted);

            return new StructuredRagResult('no_retrieval_required', $understanding, $plan, null, null, null, $timings, $understanding->limitations);
        }
        if ($plan->modules === []) {
            $timings['total'] = $this->elapsed($totalStarted);

            return new StructuredRagResult('missing_capability', $understanding, $plan, null, null, null, $timings, [...$understanding->limitations, 'no_authorized_capability']);
        }

        if (($understanding->parameters['dataset_export'] ?? false) === true) {
            $timings['total'] = $this->elapsed($totalStarted);

            return new StructuredRagResult('dataset_requested', $understanding, $plan, null, null, null, $timings, $understanding->limitations);
        }

        $stageStarted = hrtime(true);
        $decision = new RetrievalDecision(
            true,
            $understanding->intent,
            $plan->capabilities[0] ?? null,
            $plan->capabilities,
            $plan->modules[0] ?? null,
            $plan->modules,
            $plan->parameters,
            false,
            false,
            $understanding->requiresAnalysis ? 'report' : 'table',
            $this->language($message),
        );
        $retrieved = $this->retrieval->execute($decision, $scope);
        $timings['retrieval'] = $this->elapsed($stageStarted);

        $stageStarted = hrtime(true);
        $context = $this->context->build($retrieved);
        $timings['context_build'] = $this->elapsed($stageStarted);
        $limitations = array_values(array_unique([...$understanding->limitations, ...$context->limitations]));

        if (($understanding->resolvedContext['direct_response_eligible'] ?? false) === true) {
            $direct = $this->factual->compose($context->bundle, $message, $this->language($message));
            if ($direct !== null) {
                $timings['total'] = $this->elapsed($totalStarted);

                return new StructuredRagResult('direct_response_ready', $understanding, $plan, $context, null, $direct, $timings, $limitations);
            }
        }

        $stageStarted = hrtime(true);
        $input = $this->inputs->buildAnswerInput(
            $scope,
            $message,
            $history,
            $context->bundle,
            $understanding->requiresAnalysis ? 'report' : 'text',
            $this->language($message),
            $plan->missingRequirements,
            structured: true,
        );
        $timings['model_input_build'] = $this->elapsed($stageStarted);
        $timings['total'] = $this->elapsed($totalStarted);

        return new StructuredRagResult('model_input_ready', $understanding, $plan, $context, $input, null, $timings, $limitations);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function language(string $message): string
    {
        return preg_match('/[ăâđêôơưáàảãạéèẻẽẹíìỉĩịóòỏõọúùủũụýỳỷỹỵ]/iu', $message) === 1 ? 'vi' : 'en';
    }
}
