<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\ApiConnectionListRow;
use App\Models\ApiConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class ApiConnectionsListService
{
    public function __construct(
        private readonly GoogleSearchConsoleConnectionService $gscConnection,
        private readonly DataForSeoConnectionService $dataForSeo,
        private readonly SeoSerpProviderConnectionService $serpConnections,
        private readonly SeoExtendedProviderConnectionService $extendedConnections,
    ) {}

    /**
     * @return Collection<int, Model>
     */
    public function recordsForUser(int $userId): Collection
    {
        /** @var Collection<int, Model> $records */
        $records = ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderBy('name')
            ->get();

        foreach ($this->gscConnection->allForUser($userId) as $gscConnection) {
            $records->push(ApiConnectionListRow::fromGsc($gscConnection));
        }

        $dataForSeo = $this->dataForSeo->resolveForUser($userId);
        if ($dataForSeo !== null) {
            $records->push(ApiConnectionListRow::fromDataForSeo($dataForSeo));
        }

        foreach ($this->serpConnections->configuredForUser($userId) as $serpConnection) {
            $records->push(ApiConnectionListRow::fromSerpProvider($serpConnection));
        }

        foreach ($this->extendedConnections->configuredForUser($userId) as $extendedConnection) {
            $records->push(ApiConnectionListRow::fromExtendedProvider($extendedConnection));
        }

        return $records;
    }
}
