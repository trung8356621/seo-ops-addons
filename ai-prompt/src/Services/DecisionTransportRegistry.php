<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;

/**
 * Picks a callable decision protocol for a discovered model.
 * Chat completion is not a decision transport.
 */
final class DecisionTransportRegistry
{
    public function __construct(
        private readonly OpenRouterDecisionsTransport $openRouter = new OpenRouterDecisionsTransport(),
        private readonly JevCompatibleDecisionTransport $compatible = new JevCompatibleDecisionTransport(),
    ) {}

    public function resolve(ApiConnection $connection, string $model): ?DecisionTransport
    {
        if ($this->openRouter->supports($connection, $model)) {
            return $this->openRouter;
        }
        if ($this->compatible->supports($connection, $model)) {
            return $this->compatible;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowIsCallable(ApiConnection $connection, array $row): bool
    {
        foreach ($row['releases'] ?? [] as $release) {
            if (! is_array($release)) {
                continue;
            }
            $raw = (string) ($release['raw'] ?? '');
            if ($raw !== '' && $this->resolve($connection, $raw) !== null) {
                return true;
            }
        }

        return false;
    }
}
