<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot;
use Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions;
use Omnichannel\Addons\Content\Support\WritingSplitPreference;
use App\Models\ApiConnection;

/**
 * Resolves AI Center primary candidate THEN derives Writing pass mode from
 * manual writing_split_enabled preference (not free/paid).
 */
final class ArticleGenerationExecutionPlanner
{
    public function __construct(
        private readonly AiModelRouterService $router,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: RoutedAiCandidate, 1: ArticlePrimaryRoutingSnapshot, 2: array<string, mixed>}
     */
    public function plan(string $profile, AiRoutingContext $routingContext, array $variables = []): array
    {
        $primary = $this->router->resolveFirstAttemptable($profile, $routingContext);
        $freeOnlyPolicy = $this->connectionFreeOnlyPolicy($primary->connection)
            || $routingContext->freeOnly;

        $actorUserId = ($routingContext->userId !== null && $routingContext->userId > 0)
            ? $routingContext->userId
            : null;
        $splitEnabled = WritingSplitPreference::resolveForRun($variables, $actorUserId);
        $shape = ArticleGenerationShape::fromWritingSplitEnabled($splitEnabled);
        $snapshot = ArticlePrimaryRoutingSnapshot::fromCandidate(
            $primary,
            $shape,
            $freeOnlyPolicy,
            ArticleGenerationShape::SOURCE_WRITING_SPLIT_PREFERENCE,
        );

        $merged = $snapshot->mergeIntoVariables($variables);
        // Immutable run snapshot — later preference toggles must not mutate this run.
        $merged['writing_split_enabled'] = $splitEnabled;
        $merged['pass_mode'] = $splitEnabled ? 'multiple_pass' : 'single_pass';
        $merged['generation_shape_source'] = ArticleGenerationShape::SOURCE_WRITING_SPLIT_PREFERENCE;
        $merged['writing_scope'] = $splitEnabled
            ? WritingSectionScopeInstructions::SCOPE_SECTION
            : WritingSectionScopeInstructions::SCOPE_ARTICLE;

        return [$primary, $snapshot, $merged];
    }

    private function connectionFreeOnlyPolicy(?ApiConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        return (bool) ($connection->paid_locked ?? false);
    }
}
