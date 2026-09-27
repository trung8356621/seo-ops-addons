<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelCompletion;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelSource;
use Throwable;

/**
 * Resolves the active Decision Model from AI Settings and asks it for JSON.
 * Model names are not referenced here.
 */
final class AiSettingsDecisionModelGateway implements DecisionModelGateway
{
    public function __construct(
        private readonly DecisionModelSource $source,
        private readonly DecisionModelCompletion $completion,
        private readonly int $maxOutputTokens = 800,
    ) {}

    public function decide(DecisionRequest $request): DecisionResult
    {
        $models = $this->source->orderedModels($request->userId);
        $model = $models[0] ?? null;
        if ($model === null) {
            return DecisionResult::failed('decision_models_not_configured');
        }

        try {
            $text = $this->completion->complete(
                $model,
                $request->input->exportText(),
                $this->maxOutputTokens,
            );
        } catch (Throwable) {
            return DecisionResult::failed('decision_model_failed');
        }

        return new DecisionResult(true, $text);
    }
}
