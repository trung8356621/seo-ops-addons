<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Models\MatchResearchConsumerPolicy;

/**
 * Storage skeleton for consumer policies. Not connected to Topic recluster runtime.
 */
final class MatchResearchConsumerPolicyStore
{
    /** @return list<string> stable resource keys */
    public function resourceKeys(int $siteId, string $policyKey): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $row = MatchResearchConsumerPolicy::query()
            ->where('site_id', $siteId)
            ->where('policy_key', $policyKey)
            ->first();

        if ($row === null) {
            return [];
        }

        return array_values(array_filter(array_map('strval', (array) $row->resource_keys)));
    }

    /** @param list<string> $resourceKeys */
    public function put(int $siteId, string $policyKey, array $resourceKeys): void
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException('site_id is required.');
        }

        $normalized = [];
        $seen = [];
        foreach ($resourceKeys as $key) {
            $key = trim((string) $key);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $key;
        }

        MatchResearchConsumerPolicy::query()->updateOrCreate(
            ['site_id' => $siteId, 'policy_key' => $policyKey],
            ['resource_keys' => $normalized],
        );
    }
}
