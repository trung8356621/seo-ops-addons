<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use App\Models\Site;

/**
 * Dual-run shadow helper — compares V2 counters against optional V1 snapshot meta.
 * Does not delete V1 paths.
 */
final class SiteSyncDualRunComparator
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @param  array<string, int>  $v1Counters
     * @return array{enabled: bool, pass: bool|null, notes: list<string>}
     */
    public function compare(Site $site, array $v1Counters): array
    {
        if (! $this->flags->dualRunShadowEnabled()) {
            return ['enabled' => false, 'pass' => null, 'notes' => ['dual_run disabled']];
        }

        $run = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return ['enabled' => true, 'pass' => false, 'notes' => ['no completed V2 run']];
        }

        $v2 = is_array($run->counters) ? $run->counters : [];
        $notes = [];
        $pass = true;

        foreach (['articles', 'urls_synced', 'provider_keywords'] as $key) {
            if (! array_key_exists($key, $v1Counters)) {
                continue;
            }
            $a = (int) $v1Counters[$key];
            $b = (int) ($v2[$key] ?? 0);
            if ($a !== $b) {
                $pass = false;
                $notes[] = "{$key}: v1={$a} v2={$b}";
            }
        }

        if ($notes === []) {
            $notes[] = 'counters aligned or no overlapping keys';
        }

        return ['enabled' => true, 'pass' => $pass, 'notes' => $notes];
    }
}
