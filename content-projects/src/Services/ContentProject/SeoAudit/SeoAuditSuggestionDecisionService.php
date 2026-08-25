<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Support\Facades\DB;

/**
 * Project-scoped suggestion decisions (dismiss / optional accepted trace).
 */
final class SeoAuditSuggestionDecisionService
{
    /**
     * @return array<int, true> article_id => true
     */
    public function dismissedArticleIds(SeoProject $project, string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT): array
    {
        $ids = SeoContentProjectSuggestionDecision::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
            ->whereNotNull('article_id')
            ->pluck('article_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{dismissed:int, restored:int}
     */
    public function dismissArticles(
        SeoProject $project,
        array $articleIds,
        ?int $actorId = null,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT,
    ): array {
        $articleIds = $this->normalizeArticleIds($articleIds);
        if ($articleIds === []) {
            return ['dismissed' => 0, 'restored' => 0];
        }

        $count = 0;
        DB::connection('omi_seo_ai')->transaction(function () use ($project, $articleIds, $actorId, $sourceType, &$count): void {
            foreach ($articleIds as $articleId) {
                $this->upsertDecision(
                    $project,
                    $sourceType,
                    SeoContentProjectSuggestionDecision::articleSourceKey($articleId),
                    SeoContentProjectSuggestionDecision::DECISION_DISMISSED,
                    $articleId,
                    $actorId,
                );
                $count++;
            }
        });

        return ['dismissed' => $count, 'restored' => 0];
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{dismissed:int, restored:int}
     */
    public function restoreArticles(
        SeoProject $project,
        array $articleIds,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT,
    ): array {
        $articleIds = $this->normalizeArticleIds($articleIds);
        if ($articleIds === []) {
            return ['dismissed' => 0, 'restored' => 0];
        }

        $deleted = SeoContentProjectSuggestionDecision::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
            ->whereIn('article_id', $articleIds)
            ->delete();

        return ['dismissed' => 0, 'restored' => (int) $deleted];
    }

    public function markAccepted(
        SeoProject $project,
        int $articleId,
        ?int $actorId = null,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT,
        ?array $meta = null,
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $this->upsertDecision(
            $project,
            $sourceType,
            SeoContentProjectSuggestionDecision::articleSourceKey($articleId),
            SeoContentProjectSuggestionDecision::DECISION_ACCEPTED,
            $articleId,
            $actorId,
            $meta,
        );
    }

    /**
     * @return array<string, true> fingerprint => true
     */
    public function dismissedFingerprints(
        SeoProject $project,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT,
    ): array {
        $keys = SeoContentProjectSuggestionDecision::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
            ->pluck('source_key')
            ->all();

        $out = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (! str_starts_with($key, 'fp:')) {
                continue;
            }
            $fp = substr($key, 3);
            if ($fp !== '') {
                $out[$fp] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{fingerprint: string, keyword?: string, title?: string}|string>  $fingerprints
     * @return array{dismissed:int, restored:int}
     */
    public function dismissFingerprints(
        SeoProject $project,
        array $fingerprints,
        ?int $actorId = null,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT,
    ): array {
        $rows = [];
        foreach ($fingerprints as $row) {
            if (is_string($row) && trim($row) !== '') {
                $rows[] = ['fingerprint' => trim($row)];
            } elseif (is_array($row) && trim((string) ($row['fingerprint'] ?? '')) !== '') {
                $rows[] = [
                    'fingerprint' => trim((string) $row['fingerprint']),
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                ];
            }
        }
        if ($rows === []) {
            return ['dismissed' => 0, 'restored' => 0];
        }

        $count = 0;
        DB::connection('omi_seo_ai')->transaction(function () use ($project, $rows, $actorId, $sourceType, &$count): void {
            foreach ($rows as $row) {
                $fp = $row['fingerprint'];
                $this->upsertDecision(
                    $project,
                    $sourceType,
                    SeoContentProjectSuggestionDecision::fingerprintSourceKey($fp),
                    SeoContentProjectSuggestionDecision::DECISION_DISMISSED,
                    null,
                    $actorId,
                    [
                        'fingerprint' => $fp,
                        'keyword' => (string) ($row['keyword'] ?? ''),
                        'title' => (string) ($row['title'] ?? ''),
                    ],
                );
                $count++;
            }
        });

        return ['dismissed' => $count, 'restored' => 0];
    }

    /**
     * @param  list<string>  $fingerprints
     * @return array{dismissed:int, restored:int}
     */
    public function restoreFingerprints(
        SeoProject $project,
        array $fingerprints,
        string $sourceType = SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT,
    ): array {
        $keys = [];
        foreach ($fingerprints as $fp) {
            $fp = trim((string) $fp);
            if ($fp === '') {
                continue;
            }
            $keys[] = SeoContentProjectSuggestionDecision::fingerprintSourceKey($fp);
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return ['dismissed' => 0, 'restored' => 0];
        }

        $deleted = SeoContentProjectSuggestionDecision::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', $sourceType)
            ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
            ->whereIn('source_key', $keys)
            ->delete();

        return ['dismissed' => 0, 'restored' => (int) $deleted];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function upsertDecision(
        SeoProject $project,
        string $sourceType,
        string $sourceKey,
        string $decision,
        ?int $articleId,
        ?int $actorId,
        ?array $meta = null,
    ): void {
        SeoContentProjectSuggestionDecision::query()->updateOrCreate(
            [
                'project_id' => (int) $project->getKey(),
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
            ],
            [
                'site_id' => (int) ($project->site_id ?? 0) ?: null,
                'decision' => $decision,
                'article_id' => $articleId !== null && $articleId > 0 ? $articleId : null,
                'meta' => $meta,
                'created_by' => $actorId,
            ],
        );
    }

    /**
     * @param  list<int|string>  $articleIds
     * @return list<int>
     */
    private function normalizeArticleIds(array $articleIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $articleIds,
        ), static fn (int $id): bool => $id > 0)));
    }
}
