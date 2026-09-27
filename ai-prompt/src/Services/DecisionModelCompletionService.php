<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelCompletion;
use Omnichannel\Addons\AiPrompt\Contracts\ResolvedDecisionModel;
use RuntimeException;

/**
 * Runs a discovered Decision Model on its protocol.
 * OpenRouter Jev uses the Decisions API. Chat completion is not a fallback.
 */
final class DecisionModelCompletionService implements DecisionModelCompletion
{
    public function __construct(
        private readonly DecisionTransportRegistry $transports = new DecisionTransportRegistry(),
    ) {}

    public function complete(ResolvedDecisionModel $model, string $prompt, int $maxOutputTokens): string
    {
        $connection = ApiConnection::query()->find($model->connectionId);
        if (! $connection instanceof ApiConnection) {
            throw new RuntimeException('Decision model connection is missing.');
        }

        $transport = $this->transports->resolve($connection, $model->model);
        if ($transport === null) {
            throw new RuntimeException('decision_transport_unavailable');
        }

        $response = $transport->submit($connection, $model->model, $prompt);

        return DecisionAnswerMapper::toRoutingJson($response);
    }
}
