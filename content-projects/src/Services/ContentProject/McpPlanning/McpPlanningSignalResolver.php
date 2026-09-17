<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Contracts\TopicMembershipCapability;
use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve canonical site / keyword / topic for one planning item (site-scoped).
 */
final class McpPlanningSignalResolver
{
    public function __construct(
        private readonly ?CapabilityRegistry $capabilities = null,
    ) {}

    /**
     * @return array{
     *     site_id: int,
     *     topic_id: int|null,
     *     cluster_key: null,
     *     keyword_id: int|null,
     *     approved_at: string|null
     * }
     */
    public function resolve(SeoProjectTask $task, ?SeoContentProjectItemOrigin $origin = null): array
    {
        $siteId = (int) ($task->site_id ?? 0);
        $keywordId = $this->resolveKeywordId($task, $origin);
        $approvedAt = $task->planning_reviewed_at?->toIso8601String();
        $topicId = $this->resolveTopicId($siteId, $keywordId);

        return [
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'cluster_key' => null,
            'keyword_id' => $keywordId,
            'approved_at' => $approvedAt,
        ];
    }

    /**
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
            'topic_id' => $resolved['topic_id'],
            'keyword_id' => $resolved['keyword_id'],
            'approved_at' => $resolved['approved_at'],
        ]);
    }

    private function resolveTopicId(int $siteId, ?int $keywordId): ?int
    {
        if ($siteId <= 0 || $keywordId === null || $keywordId <= 0) {
            return null;
        }

        $caps = $this->capabilities ?? (app()->bound(CapabilityRegistry::class) ? app(CapabilityRegistry::class) : null);
        if (! $caps instanceof CapabilityRegistry) {
            return null;
        }
        $cap = $caps->getAs(TopicMembershipCapability::ID, TopicMembershipCapability::class);
        if (! $cap instanceof TopicMembershipCapability) {
            return null;
        }
        $map = $cap->topicIdsByKeywordId($siteId, [$keywordId]);

        return $map[$keywordId] ?? null;
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

        $focus = trim((string) ($task->focus_keyword ?? ''));
        if ($focus === '' || ! Schema::connection('omi_seo_ai')->hasTable('keywords')) {
            return null;
        }

        $keyword = Keyword::query()
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$focus])
            ->first();

        return $keyword instanceof Keyword ? (int) $keyword->id : null;
    }
}
