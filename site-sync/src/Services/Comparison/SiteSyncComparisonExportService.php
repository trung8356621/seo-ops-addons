<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Comparison;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncComparisonDiff;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncComparisonRun;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * CSV export — no article bodies. Uses Storage, not XLSX dependency for simplicity.
 */
final class SiteSyncComparisonExportService
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array{success: bool, message: string, path?: string}
     */
    public function exportCsv(Site $site, int $runId): array
    {
        if (! $this->flags->comparisonExportEnabled()) {
            return ['success' => false, 'message' => 'Comparison export disabled'];
        }

        $run = SeoSiteSyncComparisonRun::query()
            ->where('site_id', (int) $site->id)
            ->whereKey($runId)
            ->first();
        if ($run === null) {
            return ['success' => false, 'message' => 'Comparison run not found'];
        }

        $rows = [
            ['group', 'entity', 'classification', 'reason_code', 'message'],
        ];
        SeoSiteSyncComparisonDiff::query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$rows): void {
                foreach ($chunk as $diff) {
                    $rows[] = [
                        (string) $diff->group_key,
                        (string) $diff->entity_key,
                        (string) $diff->classification,
                        (string) $diff->reason_code,
                        (string) $diff->message,
                    ];
                }
            });

        $relative = 'site-sync/comparisons/site_'.(int) $site->id.'_run_'.$runId.'.csv';
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return ['success' => false, 'message' => 'Unable to open temp stream'];
        }
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        Storage::disk('local')->put($relative, $csv);
        $run->forceFill(['export_path' => $relative])->save();

        return [
            'success' => true,
            'message' => 'CSV exported',
            'path' => $relative,
        ];
    }
}
