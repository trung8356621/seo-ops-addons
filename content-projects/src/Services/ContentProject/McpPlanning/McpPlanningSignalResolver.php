<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve canonical site / keyword for one planning item (no live cluster_key reads).
 */
final class McpPlanningSignalResolver
{
    /**
     * @return array{
     *     site_id: int,
     *     cluster_key: string|null,
     *     keyword_id: int|null,
     *     approved_at: string|null
     * }
     */
    public function resolve(SeoProjectTask $task, ?SeoContentProjectItemOrigin $origin = null): array
    {
        $siteId = (int) ($task->site_id ?? 0);
        $keywordId = $this->resolveKeywordId($task, $origin);
        $approvedAt = $task->planning_reviewed_at?->toIso8601String();

        return [
            'site_id' => $siteId,
            'cluster_key' => null,
            'keyword_id' => $keywordId,
            'approved_at' => $approvedAt,
        ];
    }

    /**
     * Build a meta entry for a task that is (or was) planning-reviewed.
     *
     * @return array<string, mixed>|null
     */
    public function entryForTask(SeoProjectTask $task, ?SeoContentProjectItemOrigin $origin = null): ?array
    {
        $resolved = $this->resolve($task, $origin);
        if ($resolved['site_id'] <= 0) {
            return null;
        }

        return McpPlanningMeta::normalizeEntry([
            'project_item_id' => (int) $task->getKey(),
            'source_planning_item_id' => (int) $task->getKey(),
            'site_id' => $resolved['site_id'],
            'cluster_key' => null,
            'keyword_id' => $resolved['keyword_id'],
            'approved_at' => $resolved['approved_at'],
        ]);
    }

    private function resolveKeywordId(SeoProjectTask $task, ?SeoContentProjectItemOrigin $origin): ?int
    {
        if ($origin instanceof SeoContentProjectItemOrigin) {
            $findings = is_array($origin->source_finding_ids) ? $origin->source_finding_ids : [];
            foreach ($findings as $finding) {
                $id = (int) $finding;
                if ($id > 0) {
                    return $id;
                }
            }

            $codes = is_array($origin->reason_codes) ? $origin->reason_codes : [];
            foreach ($codes as $code) {
                $code = (string) $code;
                if (str_starts_with($code, 'source_keyword_id:')) {
                    $id = (int) substr($code, strlen('source_keyword_id:'));
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        $siteId = (int) ($task->site_id ?? 0);
        $phrase = trim((string) ($task->keyword ?? ''));
        if ($phrase === '') {
            $phrase = trim((string) ($task->source_content ?? ''));
        }
        if ($phrase === '' || $siteId <= 0) {
            return null;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('keywords')) {
            return null;
        }

        $prepared = Keyword::preparePhraseForStorage($phrase);
        if ($prepared === '') {
            return null;
        }

        $keyword = Keyword::query()
            ->forSite($siteId)
            ->where('phrase', $prepared)
            ->orderBy('id')
            ->first(['id']);

        return $keyword instanceof Keyword ? (int) $keyword->getKey() : null;
    }
}
