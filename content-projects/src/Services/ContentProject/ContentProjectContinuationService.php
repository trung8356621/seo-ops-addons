<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Monthly continuation of ONE project chain (site + assigned writer).
 *
 * Never resolves "any project for this domain/month".
 */
final class ContentProjectContinuationService
{
    public const MAX_MONTH_HOPS = 36;

    public function __construct(
        private readonly ?ContentProjectCommandBus $commandBus = null,
    ) {}

    public function nextMonth(Carbon $month): Carbon
    {
        return $month->copy()->startOfMonth()->addMonthNoOverflow()->startOfMonth();
    }

    /**
     * Chain identity: assigned writer (`user_id`) + site + monthly kind.
     *
     * @return Builder<SeoProject>
     */
    public function chainQuery(SeoProject $source): Builder
    {
        $siteId = (int) ($source->site_id ?? 0);
        $userId = (int) ($source->user_id ?? 0);

        return SeoProject::query()
            ->where('site_id', $siteId)
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->where(function (Builder $query): void {
                $query->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            });
    }

    public function findInChainForMonth(SeoProject $source, Carbon|string $month, bool $lock = true): ?SeoProject
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.domain_required'),
            ]);
        }

        $monthDate = Carbon::parse($month)->startOfMonth()->format('Y-m-d');

        $query = $this->chainQuery($source)
            ->whereDate('month', $monthDate)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $found = $query->first();

        return $found instanceof SeoProject ? $found : null;
    }

    public function findOrCreateContinuation(SeoProject $source, Carbon|string $month): SeoProject
    {
        $carbonMonth = Carbon::parse($month)->startOfMonth();

        return $this->withChainLock($source, function () use ($source, $carbonMonth): SeoProject {
            $existing = $this->findInChainForMonth($source, $carbonMonth, lock: true);
            if ($existing instanceof SeoProject) {
                return $existing;
            }

            $this->cloneForMonth($source, $carbonMonth);

            $created = $this->findInChainForMonth($source, $carbonMonth, lock: true);
            if ($created instanceof SeoProject) {
                return $created;
            }

            throw new RuntimeException('Failed to create monthly continuation project.');
        });
    }

    public function findOrCreateNextMonth(SeoProject $source): SeoProject
    {
        return $this->findOrCreateContinuation($source, $this->nextMonth($source->monthCarbon()));
    }

    /**
     * Configuration/ownership clone. Does NOT copy items, runs, archives, or counters.
     *
     * @return array<string, mixed>
     */
    public function continuationAttributes(SeoProject $source, Carbon|string $month): array
    {
        $carbonMonth = Carbon::parse($month)->startOfMonth();
        $siteId = (int) ($source->site_id ?? 0);
        $userId = (int) ($source->user_id ?? 0);

        return [
            'name' => SeoProject::defaultNameFromMonth($carbonMonth),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $carbonMonth->format('Y-m-d'),
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'description' => $source->description,
        ];
    }

    public function cloneForMonth(SeoProject $source, Carbon|string $month): SeoProject
    {
        $attrs = $this->continuationAttributes($source, $month);

        if ($this->commandBus instanceof ContentProjectCommandBus) {
            try {
                $result = $this->commandBus->dispatch(
                    new CreateContentProjectCommand($attrs),
                    ActorContext::system(),
                );
                if ($result->success && $result->projectId !== null && $result->projectId > 0) {
                    $created = SeoProject::query()->find($result->projectId);
                    if ($created instanceof SeoProject) {
                        return $created;
                    }
                }
            } catch (\Throwable) {
                // Fall through to canonical row create.
            }
        }

        return SeoProject::query()->create($attrs);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withChainLock(SeoProject $source, callable $callback): mixed
    {
        $siteId = (int) ($source->site_id ?? 0);
        $userId = (int) ($source->user_id ?? 0);
        $lockName = sprintf('cp_chain_%d_%d', $siteId, $userId);
        $connection = DB::connection($source->getConnectionName());
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockName]);
            try {
                return $callback();
            } finally {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }

        $this->chainQuery($source)->lockForUpdate()->get();

        return $callback();
    }
}
