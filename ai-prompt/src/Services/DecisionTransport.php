<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;

interface DecisionTransport
{
    public function supports(ApiConnection $connection, string $model): bool;

    public function endpoint(): string;

    /**
     * @return array<string, mixed> provider response
     */
    public function submit(ApiConnection $connection, string $model, string $state): array;
}
