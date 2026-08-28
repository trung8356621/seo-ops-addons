<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;

/**
 * Map Google URL Inspection machine fields → Index Health check status.
 * Never emits dropped (derived by ArticleIndexHealthRecorder).
 */
final class GscUrlInspectionHealthMapper
{
    public function map(GscUrlInspectionResult $result): ArticleIndexCheckStatus
    {
        $verdict = strtoupper(trim((string) ($result->verdict ?? '')));

        return match ($verdict) {
            'PASS' => ArticleIndexCheckStatus::Indexed,
            // On Google but with issues — still indexed for checklist purposes.
            'PARTIAL' => ArticleIndexCheckStatus::Indexed,
            // Explicitly not on Google, or unknown to Google (neither indexed nor excluded).
            'FAIL', 'NEUTRAL' => ArticleIndexCheckStatus::NotIndexed,
            'VERDICT_UNSPECIFIED', '' => ArticleIndexCheckStatus::Unknown,
            default => ArticleIndexCheckStatus::Unknown,
        };
    }
}
