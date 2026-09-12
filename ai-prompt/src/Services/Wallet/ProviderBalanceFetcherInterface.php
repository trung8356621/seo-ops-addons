<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Wallet;

use App\Models\ApiConnection;

interface ProviderBalanceFetcherInterface
{
    public function supports(string $provider): bool;

    public function fetch(ApiConnection $connection): ProviderBalanceResult;
}
