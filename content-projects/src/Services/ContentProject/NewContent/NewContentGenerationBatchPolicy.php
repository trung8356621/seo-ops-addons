<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\PromptBudget\KeywordDiscoveryBudgetStrategy;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Throwable;

/**
 * Model-aware safe batch sizes for keyword.discovery.structured.
 * Size comes from context budget — not hard-coded free=10 / paid=20 only.
 */
final class NewContentGenerationBatchPolicy
{
    public const MAX_TOTAL_PLANNER_IDEAS = 200;

    /** Operational floors/ceilings — actual size is budget-derived within these. */
    public const BATCH_FREE_OR_WEAK = 10;

    public const BATCH_PAID_STANDARD = 20;

    public const CLASS_FREE_OR_WEAK = 'free_or_weak';

    public const CLASS_PAID_STANDARD = 'paid_standard';

    public function __construct(
        private readonly ?PromptBudgetPreflightService $budgetPreflight = null,
        private readonly ?KeywordDiscoveryBudgetStrategy $discoveryStrategy = null,
    ) {}

    /**
     * Resolve per-call idea cap from leading route capability + optional prompt estimate.
     *
     * @return array{
     *   batch_size: int,
     *   model_class: string,
     *   leading_is_free: bool|null,
     *   budget?: array<string, mixed>
     * }
     */
    public function resolveBatchSize(?int $actorId = null, ?string $immutableBrief = null, int $remaining = 0, int $continuationTokens = 0): array
    {
        $peek = $this->peekLeadingCandidate($actorId);
        $isFree = $peek['is_free'];
        $class = $isFree === false ? self::CLASS_PAID_STANDARD : self::CLASS_FREE_OR_WEAK;
        $legacyCap = $class === self::CLASS_PAID_STANDARD
            ? self::BATCH_PAID_STANDARD
            : self::BATCH_FREE_OR_WEAK;

        $remaining = $remaining > 0 ? $remaining : $legacyCap;
        $budgetMeta = [];

        if ($peek['candidate'] !== null) {
            try {
                $preflight = $this->budgetPreflight
                    ?? (function_exists('app') ? app(PromptBudgetPreflightService::class) : new PromptBudgetPreflightService());
                $strategy = $this->discoveryStrategy ?? new KeywordDiscoveryBudgetStrategy();
                $capability = $preflight->capabilities()->resolve($peek['candidate']);
                $estimator = $preflight->estimator();
                $immutableTokens = $immutableBrief !== null && $immutableBrief !== ''
                    ? $estimator->estimate($immutableBrief, $capability->estimatorFamily)
                    : 2_400;
                $target = $strategy->resolveBatchTarget(
                    $remaining,
                    $immutableTokens,
                    max(0, $continuationTokens),
                    $capability,
                );
                if ($target > 0) {
                    $budgetMeta = array_merge($capability->toDiagnostics(), [
                        'immutable_tokens' => $immutableTokens,
                        'continuation_tokens' => $continuationTokens,
                        'budget_batch_target' => $target,
                        'legacy_cap' => $legacyCap,
                    ]);

                    return [
                        'batch_size' => min($legacyCap * 2, max(KeywordDiscoveryBudgetStrategy::MIN_BATCH, $target)),
                        'model_class' => $class,
                        'leading_is_free' => $isFree,
                        'budget' => $budgetMeta,
                    ];
                }
            } catch (Throwable) {
                // Fall through to legacy class caps.
            }
        }

        return [
            'batch_size' => $legacyCap,
            'model_class' => $class,
            'leading_is_free' => $isFree,
            'budget' => $budgetMeta,
        ];
    }

    public function clampTotalDemand(int $total): int
    {
        return max(0, min(self::MAX_TOTAL_PLANNER_IDEAS, $total));
    }

    public function exceedsHardCeiling(int $total): bool
    {
        return $total > self::MAX_TOTAL_PLANNER_IDEAS;
    }

    /**
     * @return array{candidate: \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate|null, is_free: bool|null}
     */
    private function peekLeadingCandidate(?int $actorId): array
    {
        try {
            $router = app(AiModelRouterService::class);
            $context = new AiRoutingContext(
                userId: $actorId !== null && $actorId > 0 ? $actorId : null,
            );
            // Prefer longform profile (keyword.discovery map) — not reasoning/reasoner.
            $candidate = $router->resolve(AiExecutionProfile::TextLongform->value, $context);

            return [
                'candidate' => $candidate,
                'is_free' => (bool) $candidate->isFree,
            ];
        } catch (Throwable) {
            return ['candidate' => null, 'is_free' => null];
        }
    }
}
