<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPack;
use Throwable;

/**
 * Pack status / health transitions (no business mutation).
 */
final class AgentPackStateService
{
    /**
     * @return array{ok: bool, status?: string, health?: string}
     */
    public function markIncompatible(string $packHashId, string $reason = ''): array
    {
        return $this->update($packHashId, 'incompatible', 'unhealthy', $reason);
    }

    /**
     * @return array{ok: bool, status?: string, health?: string}
     */
    public function markUnhealthy(string $packHashId, string $reason = ''): array
    {
        return $this->update($packHashId, 'unhealthy', 'unhealthy', $reason);
    }

    /**
     * @return array{ok: bool, status?: string, health?: string}
     */
    private function update(string $packHashId, string $status, string $health, string $reason): array
    {
        try {
            $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
            if ($pack === null) {
                return ['ok' => false];
            }
            $pack->status = $status;
            $pack->health = $health;
            $meta = is_array($pack->metadata_json) ? $pack->metadata_json : [];
            if ($reason !== '') {
                $meta['state_reason'] = $reason;
            }
            $pack->metadata_json = $meta;
            $pack->save();

            return ['ok' => true, 'status' => $status, 'health' => $health];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
