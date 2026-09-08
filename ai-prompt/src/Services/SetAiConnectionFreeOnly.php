<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use RuntimeException;

/**
 * Toggle api_connections.paid_locked (UI: Free only) without touching status.
 */
final class SetAiConnectionFreeOnly
{
    public function __construct(
        private readonly AiConnectionFreeOnlySupport $freeOnlySupport = new AiConnectionFreeOnlySupport(),
        private readonly AiConnectionInventoryService $inventory = new AiConnectionInventoryService(),
    ) {}

    public function handle(ApiConnection $connection, bool $freeOnly): ApiConnection
    {
        if (! ApiConnectionProviders::isAi((string) $connection->provider)) {
            throw new RuntimeException('Only AI connections support Free only.');
        }

        if ($freeOnly && ! $this->freeOnlySupport->supports($connection)) {
            throw new RuntimeException('Không có model miễn phí khả dụng');
        }

        if (! $this->columnReady($connection)) {
            throw new RuntimeException('paid_locked column is not available.');
        }

        $connection->paid_locked = $freeOnly;
        $connection->save();
        $this->inventory->forgetCache();

        return $connection->refresh();
    }

    private function columnReady(ApiConnection $connection): bool
    {
        $name = (string) ($connection->getConnectionName() ?: config('database.core_connection', 'mysql'));

        return Schema::connection($name)->hasColumn('api_connections', 'paid_locked');
    }
}
