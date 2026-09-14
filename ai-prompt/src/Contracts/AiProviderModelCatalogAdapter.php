<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\ProviderModelCatalogResult;
use Omnichannel\Addons\AiPrompt\Support\AiModelCatalogAuthorityMode;

/**
 * Per-provider model catalog discovery contract.
 *
 * Business services must not switch on provider-specific model lists;
 * they call this adapter (or the canonical sync entry that uses it).
 */
interface AiProviderModelCatalogAdapter
{
    public function supportsDiscovery(ApiConnection $connection): bool;

    public function authorityMode(ApiConnection $connection): AiModelCatalogAuthorityMode;

    public function discover(ApiConnection $connection): ProviderModelCatalogResult;
}
