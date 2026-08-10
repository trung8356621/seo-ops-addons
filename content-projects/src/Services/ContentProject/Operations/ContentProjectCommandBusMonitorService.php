<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Query operation log rows — read-only monitor.
 */
final class ContentProjectCommandBusMonitorService
{
    /**
     * @param  array{
     *     project_ref?: string|null,
     *     command?: string|null,
     *     capability?: string|null,
     *     actor_type?: string|null,
     *     result_code?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     tenant_ref?: string|null,
     *     q?: string|null,
     *     per_page?: int|null,
     *     limit?: int|null,
     * }  $filters
     */
    public function query(array $filters): Builder|Collection|LengthAwarePaginator
    {
        $query = ContentProjectOperation::query()->orderByDesc('finished_at');

        if (! empty($filters['project_ref'])) {
            $query->where('project_ref', (string) $filters['project_ref']);
        }

        if (! empty($filters['command'])) {
            $query->where('command', (string) $filters['command']);
        }

        if (! empty($filters['capability'])) {
            $query->where('command', (string) $filters['capability']);
        }

        if (! empty($filters['actor_type'])) {
            $query->where('actor_type', (string) $filters['actor_type']);
        }

        if (! empty($filters['result_code'])) {
            $query->where('result_code', (string) $filters['result_code']);
        }

        if (! empty($filters['tenant_ref'])) {
            $query->where('tenant_ref', (string) $filters['tenant_ref']);
        }

        if (! empty($filters['from'])) {
            $query->where('finished_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('finished_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('operation_id', 'like', $term)
                    ->orWhere('request_id', 'like', $term)
                    ->orWhere('command', 'like', $term)
                    ->orWhere('result_code', 'like', $term)
                    ->orWhere('project_ref', 'like', $term)
                    ->orWhere('item_ref', 'like', $term);
            });
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 0;
        if ($perPage > 0) {
            return $query->paginate($perPage);
        }

        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 500;

        return $query->limit($limit)->get();
    }
}
