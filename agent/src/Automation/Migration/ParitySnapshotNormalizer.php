<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

/**
 * Canonical parity snapshot — chỉ scalar/ID/state; không body/secret.
 */
final class ParitySnapshotNormalizer
{
    /**
     * @param  array<string, mixed>|object  $raw
     * @return array{
     *   ids: array{project_id: int|null},
     *   resulting_state: array{added: int, duplicate: int, overflow: int, domain_mismatch: int, already_in_project: int},
     *   deduplication: array{duplicate: int, already_in_project: int},
     *   links: array{tasks_created: int},
     *   status_transition: null,
     *   changed: bool,
     *   noop: bool,
     *   wrong_context: bool
     * }
     */
    public function assignment(mixed $raw, ?int $projectId = null): array
    {
        $summary = $this->extractAssignmentSummary($raw);

        $added = $summary['added'];
        $duplicate = $summary['duplicate'];
        $domainMismatch = $summary['domain_mismatch'];
        $already = $summary['already_in_project'];

        return [
            'ids' => [
                'project_id' => $projectId,
            ],
            'resulting_state' => $summary,
            'deduplication' => [
                'duplicate' => $duplicate,
                'already_in_project' => $already,
            ],
            'links' => [
                'tasks_created' => $added,
            ],
            'status_transition' => null,
            'changed' => $added > 0,
            'noop' => $added === 0 && ($duplicate > 0 || $already > 0),
            'wrong_context' => $domainMismatch > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   ids: array{task_id: int, article_id: int, site_id: int|null},
     *   resulting_state: array{task_article_id: int, linked: bool},
     *   deduplication: array{already_attached: bool},
     *   links: array{article_linked: bool},
     *   status_transition: null,
     *   changed: bool,
     *   noop: bool,
     *   wrong_context: bool
     * }
     */
    public function attach(array $raw, bool $alreadyAttached = false, ?int $siteId = null): array
    {
        $taskId = (int) ($raw['task_id'] ?? 0);
        $articleId = (int) ($raw['article_id'] ?? 0);

        return [
            'ids' => [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'site_id' => $siteId,
            ],
            'resulting_state' => [
                'task_article_id' => $articleId,
                'linked' => $articleId > 0,
            ],
            'deduplication' => [
                'already_attached' => $alreadyAttached,
            ],
            'links' => [
                'article_linked' => $articleId > 0,
            ],
            'status_transition' => null,
            'changed' => ! $alreadyAttached && $articleId > 0,
            'noop' => $alreadyAttached,
            'wrong_context' => $taskId <= 0 || $articleId <= 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   ids: array{task_id: int, article_id: int|null, site_id: int|null},
     *   resulting_state: array{status: string, task_article_id: int|null},
     *   deduplication: array{already_completed: bool},
     *   links: array{article_linked: bool, owner_sync_expected: bool},
     *   status_transition: array{to: string},
     *   changed: bool,
     *   noop: bool,
     *   wrong_context: bool
     * }
     */
    public function markCompleted(
        array $raw,
        bool $alreadyCompleted = false,
        ?int $siteId = null,
    ): array {
        $taskId = (int) ($raw['task_id'] ?? 0);
        $articleId = isset($raw['article_id']) && $raw['article_id'] !== null
            ? (int) $raw['article_id']
            : null;
        $status = (string) ($raw['status'] ?? 'completed');

        return [
            'ids' => [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'site_id' => $siteId,
            ],
            'resulting_state' => [
                'status' => $status,
                'task_article_id' => $articleId,
            ],
            'deduplication' => [
                'already_completed' => $alreadyCompleted,
            ],
            'links' => [
                'article_linked' => $articleId !== null && $articleId > 0,
                'owner_sync_expected' => $articleId !== null && $articleId > 0,
            ],
            'status_transition' => [
                'to' => $status,
            ],
            'changed' => ! $alreadyCompleted,
            'noop' => $alreadyCompleted,
            'wrong_context' => $taskId <= 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function articleCreate(array $raw): array
    {
        $out = (new ArticleActionOutputNormalizer)->create($raw);

        return [
            'ids' => [
                // Shadow plan không biết id bài mới — chỉ so id khi deduplicated.
                'article_id' => $out['deduplicated'] ? $out['article_id'] : null,
                'site_id' => $out['site_id'],
            ],
            'resulting_state' => [
                'status' => $out['status'],
                'post_type' => $out['post_type'] ?? null,
                'would_create' => (bool) ($out['would_create'] ?? false),
            ],
            'deduplication' => [
                'deduplicated' => $out['deduplicated'],
            ],
            'links' => [
                'origin_attached' => (bool) ($raw['origin_attached'] ?? false),
            ],
            'status_transition' => $out['deduplicated'] ? null : ['to' => $out['status'] ?? 'draft'],
            'changed' => $out['changed'],
            'noop' => $out['deduplicated'],
            'wrong_context' => ($out['site_id'] ?? 0) <= 0,
            'changed_fields' => $out['changed_fields'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function articleContent(array $raw): array
    {
        $out = (new ArticleActionOutputNormalizer)->content($raw);

        return [
            'ids' => [
                'article_id' => $out['article_id'],
                'entity_id' => $out['entity_id'],
            ],
            'resulting_state' => [
                'status' => $out['status'],
                'content_hash' => $out['content_hash'],
                'updated_at' => $out['updated_at'],
            ],
            'deduplication' => [
                'noop' => $out['deduplicated'],
            ],
            'links' => [],
            'status_transition' => null,
            'changed' => $out['changed'],
            'noop' => $out['deduplicated'],
            'wrong_context' => ($out['article_id'] ?? 0) <= 0,
            'changed_fields' => $out['changed_fields'],
            'conflict' => (bool) ($raw['conflict'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function articleSeoMeta(array $raw): array
    {
        $out = (new ArticleActionOutputNormalizer)->seoMeta($raw);

        return [
            'ids' => [
                'article_id' => $out['article_id'],
                'entity_id' => $out['entity_id'],
            ],
            'resulting_state' => [
                'slug' => $out['slug'] ?? null,
                'focus_keyword_set' => ($out['focus_keyword'] ?? '') !== '',
                'seo_analysis_pending' => $out['seo_analysis_pending'],
            ],
            'deduplication' => [
                'noop' => $out['deduplicated'],
            ],
            'links' => [
                'focus_keyword' => ($out['focus_keyword'] ?? '') !== '',
            ],
            'status_transition' => null,
            'changed' => $out['changed'],
            'noop' => $out['deduplicated'],
            'wrong_context' => ($out['article_id'] ?? 0) <= 0,
            'changed_fields' => $out['changed_fields'],
        ];
    }

    /**
     * @return array{added: int, duplicate: int, overflow: int, domain_mismatch: int, already_in_project: int}
     */
    private function extractAssignmentSummary(mixed $raw): array
    {
        if ($raw instanceof \Omnichannel\Addons\Agent\Automation\Data\ActionResult) {
            $raw = is_array($raw->output['summary'] ?? null)
                ? $raw->output['summary']
                : $raw->output;
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'added' => (int) ($raw['added'] ?? 0),
            'duplicate' => (int) ($raw['duplicate'] ?? 0),
            'overflow' => (int) ($raw['overflow'] ?? 0),
            'domain_mismatch' => (int) ($raw['domain_mismatch'] ?? 0),
            'already_in_project' => (int) ($raw['already_in_project'] ?? 0),
        ];
    }
}
