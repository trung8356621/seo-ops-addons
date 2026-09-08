<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use RuntimeException;

/**
 * Toggle api_connections.status active/inactive without touching paid_locked.
 */
final class SetAiConnectionActive
{
    public function __construct(
        private readonly AiConnectionInventoryService $inventory = new AiConnectionInventoryService(),
    ) {}

    public function handle(ApiConnection $connection, bool $active): ApiConnection
    {
        if (! ApiConnectionProviders::isAi((string) $connection->provider)) {
            throw new RuntimeException('Only AI connections support active toggle.');
        }

        if ((bool) $connection->is_global) {
            throw new RuntimeException('Global connections cannot be toggled here.');
        }

        $connection->status = $active ? 'active' : 'inactive';
        $connection->save();
        $this->inventory->forgetCache();

        return $connection->refresh();
    }
}
