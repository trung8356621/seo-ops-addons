<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Illuminate\Support\Facades\Cache;

/**
 * Pack compile cache — scoped invalidation only (no global flush).
 */
final class AgentPackCache
{
    public function key(string $packKey, string $revisionHash, string $scope = 'global'): string
    {
        return implode(':', [
            'agent_pack',
            AgentPackConstants::BUILD_VERSION,
            $scope,
            $packKey,
            $revisionHash,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $packKey, string $revisionHash, string $scope = 'global'): ?array
    {
        try {
            $value = Cache::get($this->key($packKey, $revisionHash, $scope));

            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $compiled
     */
    public function put(string $packKey, string $revisionHash, array $compiled, string $scope = 'global'): void
    {
        try {
            Cache::put($this->key($packKey, $revisionHash, $scope), $compiled, now()->addDay());
        } catch (\Throwable) {
            // fail-open
        }
    }

    public function forgetPack(string $packKey): void
    {
        try {
            // Tag-less environments: store index of revision hashes.
            $indexKey = 'agent_pack_index:'.AgentPackConstants::BUILD_VERSION.':'.$packKey;
            $hashes = Cache::get($indexKey, []);
            if (is_array($hashes)) {
                foreach ($hashes as $hash) {
                    Cache::forget($this->key($packKey, (string) $hash, 'global'));
                }
            }
            Cache::forget($indexKey);
        } catch (\Throwable) {
            // fail-open
        }
    }

    public function rememberIndex(string $packKey, string $revisionHash): void
    {
        try {
            $indexKey = 'agent_pack_index:'.AgentPackConstants::BUILD_VERSION.':'.$packKey;
            $hashes = Cache::get($indexKey, []);
            if (! is_array($hashes)) {
                $hashes = [];
            }
            $hashes[] = $revisionHash;
            Cache::put($indexKey, array_values(array_unique($hashes)), now()->addDays(7));
        } catch (\Throwable) {
            // fail-open
        }
    }
}
