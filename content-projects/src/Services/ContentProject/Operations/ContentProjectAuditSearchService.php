<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit search — business audits only, never prompt/output text.
 */
final class ContentProjectAuditSearchService
{
    private const CONNECTION = 'omi_seo_ai';

    /**
     * @param  array{
     *     project_ref?: string|null,
     *     article_ref?: string|null,
     *     actor_type?: string|null,
     *     action?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     limit?: int|null,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('seo_content_project_business_audits')) {
            return [];
        }

        $query = DB::connection(self::CONNECTION)
            ->table('seo_content_project_business_audits')
            ->orderByDesc('occurred_at');

        if (! empty($filters['project_ref'])) {
            $query->where('project_ref', (string) $filters['project_ref']);
        }

        if (! empty($filters['article_ref']) || ! empty($filters['item_ref'])) {
            $ref = (string) ($filters['article_ref'] ?? $filters['item_ref']);
            $query->where('item_ref', $ref);
        }

        if (! empty($filters['actor_type'])) {
            $query->where('actor_type', (string) $filters['actor_type']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.(string) $filters['action'].'%');
        }

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', (string) $filters['to']);
        }

        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 50;

        return $query->limit($limit)->get()->map(static function ($row): array {
            return [
                'occurred_at' => isset($row->occurred_at) ? (string) $row->occurred_at : null,
                'action' => isset($row->action) ? (string) $row->action : null,
                'actor_type' => isset($row->actor_type) ? (string) $row->actor_type : null,
                'actor_id' => $row->actor_id ?? null,
                'project_ref' => isset($row->project_ref) ? (string) $row->project_ref : null,
                'item_ref' => isset($row->item_ref) ? (string) $row->item_ref : null,
                'result' => isset($row->result) ? (string) $row->result : null,
                'result_code' => isset($row->result_code) ? (string) $row->result_code : null,
            ];
        })->all();
    }
}
