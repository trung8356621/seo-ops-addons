<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot;
use App\Models\ApiConnection;

/**
 * Resolves AI Center primary candidate THEN derives prompt shape (free → sectioned, paid → single_pass).
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
        $shape = ArticleGenerationShape::fromPrimaryIsFree($primary->isFree);
        $snapshot = ArticlePrimaryRoutingSnapshot::fromCandidate(
            $primary,
            $shape,
            $freeOnlyPolicy,
        );

        return [$primary, $snapshot, $snapshot->mergeIntoVariables($variables)];
    }

    private function connectionFreeOnlyPolicy(?ApiConnection $connection): bool
    {
        if (! $connection instanceof ApiConnection) {
            return false;
        }

        if (array_key_exists('paid_locked', $connection->getAttributes())) {
            return (bool) $connection->getAttribute('paid_locked');
        }

        return (bool) ($connection->paid_locked ?? false);
    }
}
