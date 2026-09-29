<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelSource;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AnswerTextCompletionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Throwable;

final class AiSettingsAssumedModelResolver implements AssumedModelResolver
{
    public function __construct(
        private readonly ?DecisionModelSource $decisionSource = null,
        private readonly ?AiRoutingTargetService $routingTargets = null,
    ) {}

    public function resolveDecisionModel(int $userId): AssumedModelMetadata
    {
        $source = $this->decisionSource ?? (app()->bound(DecisionModelSource::class) ? app(DecisionModelSource::class) : null);
        if ($source === null) {
            return new AssumedModelMetadata(
                stage: 'decision',
                profile: AiExecutionProfile::DecisionRoute->value,
                status: 'not_configured',
            );
        }

        try {
            $models = $source->orderedModels($userId);
        } catch (Throwable) {
            $models = [];
        }

        if ($models === []) {
            return new AssumedModelMetadata(
                stage: 'decision',
                profile: AiExecutionProfile::DecisionRoute->value,
                status: 'decision_models_not_configured',
            );
        }

        $primary = $models[0];
        $fallbacks = [];
        for ($i = 1, $len = count($models); $i < $len; $i++) {
            $m = $models[$i];
            $fallbacks[] = new AssumedModelCandidate(
                provider: $m->provider,
                model: $m->model,
                displayName: $m->displayName,
                priority: $m->priority,
            );
        }

        return new AssumedModelMetadata(
            stage: 'decision',
            profile: AiExecutionProfile::DecisionRoute->value,
            provider: $primary->provider,
            model: $primary->model,
            displayName: $primary->displayName,
            fallbacks: $fallbacks,
            routingMode: 'priority_order',
            status: 'available',
        );
    }

    public function resolveAnswerModel(int $userId): AssumedModelMetadata
    {
        $targets = $this->routingTargets ?? (app()->bound(AiRoutingTargetService::class) ? app(AiRoutingTargetService::class) : null);
        $profile = AiExecutionProfile::TextReasoning;

        if ($targets === null) {
            return new AssumedModelMetadata(
                stage: 'answer',
                profile: $profile->value,
                status: 'not_configured',
            );
        }

        try {
            $context = new AiRoutingContext(
                userId: $userId,
                hookKey: AnswerTextCompletionService::HOOK_KEY,
                canonicalPromptKey: AnswerTextCompletionService::HOOK_KEY,
                promptTaskType: 'agent_runtime_answer',
                modelArea: $profile->value,
            );
            $candidates = $targets->eligibleCandidates($userId, $profile, $context);
        } catch (Throwable) {
            $candidates = [];
        }

        if ($candidates === []) {
            return new AssumedModelMetadata(
                stage: 'answer',
                profile: $profile->value,
                status: 'no_eligible_models',
            );
        }

        /** @var RoutedAiCandidate $primary */
        $primary = $candidates[0];
        $fallbacks = [];
        for ($i = 1, $len = count($candidates); $i < $len; $i++) {
            /** @var RoutedAiCandidate $c */
            $c = $candidates[$i];
            $fallbacks[] = new AssumedModelCandidate(
                provider: $c->provider,
                model: $c->model,
                displayName: $c->model,
                capabilities: $c->capabilities,
                priority: $c->priority,
                isFree: $c->isFree,
            );
        }

        return new AssumedModelMetadata(
            stage: 'answer',
            profile: $profile->value,
            provider: $primary->provider,
            model: $primary->model,
            displayName: $primary->model,
            fallbacks: $fallbacks,
            routingMode: 'text.reasoning',
            status: 'available',
        );
    }
}
