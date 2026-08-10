<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use Carbon\Carbon;

final class AgentKnowledgeFreshnessService
{
    /**
     * @return array{usable: bool, stale: bool, expired: bool, warning: string|null}
     */
    public function evaluate(SeoAgentKnowledgeItem|array $item, bool $allowStaleWithWarning = true): array
    {
        $status = is_array($item) ? (string) ($item['status'] ?? '') : (string) $item->status;
        $validUntil = is_array($item)
            ? ($item['valid_until'] ?? null)
            : $item->valid_until?->toIso8601String();
        $lastVerified = is_array($item)
            ? ($item['last_verified_at'] ?? null)
            : $item->last_verified_at?->toIso8601String();

        if (in_array($status, ['disabled', 'superseded', 'expired', 'rejected'], true)) {
            return ['usable' => false, 'stale' => false, 'expired' => true, 'warning' => 'status_'.$status];
        }

        $now = Carbon::now();
        if (is_string($validUntil) && $validUntil !== '') {
            try {
                if (Carbon::parse($validUntil)->lt($now)) {
                    return ['usable' => false, 'stale' => false, 'expired' => true, 'warning' => 'expired'];
                }
            } catch (\Throwable) {
                // ignore parse
            }
        }

        $stale = false;
        $warning = null;
        if (is_string($lastVerified) && $lastVerified !== '') {
            try {
                if (Carbon::parse($lastVerified)->lt($now->copy()->subDays(90))) {
                    $stale = true;
                    $warning = 'stale_verification';
                }
            } catch (\Throwable) {
                // ignore
            }
        } elseif ($lastVerified === null) {
            $stale = true;
            $warning = 'never_verified';
        }

        if ($stale && ! $allowStaleWithWarning) {
            return ['usable' => false, 'stale' => true, 'expired' => false, 'warning' => $warning];
        }

        return ['usable' => true, 'stale' => $stale, 'expired' => false, 'warning' => $warning];
    }

    public function markVerified(SeoAgentKnowledgeItem $item): void
    {
        $item->last_verified_at = Carbon::now();
        $item->save();
    }

    public function markStale(SeoAgentKnowledgeItem $item): void
    {
        $item->last_verified_at = Carbon::now()->subDays(120);
        $item->save();
    }
}
