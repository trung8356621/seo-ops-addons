<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use App\Models\ApiConnection;

final class RoutedAiCandidate
{
    /**
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $profile,
        public readonly ApiConnection $connection,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $capabilities,
        public readonly int $priority,
        public readonly array $options = [],
        public readonly ?int $seoAiModelId = null,
        public readonly bool $legacyFallback = false,
        public readonly bool $isFree = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'profile' => $this->profile,
            'provider' => $this->provider,
            'connection_id' => (int) $this->connection->id,
            'connection_name' => (string) $this->connection->name,
            'model' => $this->model,
            'capabilities' => $this->capabilities,
            'priority' => $this->priority,
            'legacy_fallback' => $this->legacyFallback,
            'seo_ai_model_id' => $this->seoAiModelId,
            'is_free' => $this->isFree,
        ];
    }
}
