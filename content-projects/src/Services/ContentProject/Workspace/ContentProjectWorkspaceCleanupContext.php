<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Shared scope cho mọi Workspace Cleaner trong một lần Archive.
 */
final class ContentProjectWorkspaceCleanupContext
{
    /** @var list<string> */
    private array $diskPathsToDelete = [];

    /** @var list<string> */
    private array $cacheLockKeys = [];

    /** @var array<string, int> */
    private array $stats = [];

    /**
     * @param  list<int>  $articleIds
     * @param  list<int>  $taskIds
     * @param  list<int>  $runIds
     */
    public function __construct(
        public readonly SeoProject $project,
        public readonly array $articleIds,
        public readonly array $taskIds,
        public readonly array $runIds,
    ) {}

    public function projectId(): int
    {
        return (int) $this->project->getKey();
    }

    /**
     * @return list<int>
     */
    public function articleIds(): array
    {
        return $this->articleIds;
    }

    /**
     * @return list<int>
     */
    public function taskIds(): array
    {
        return $this->taskIds;
    }

    /**
     * @return list<int>
     */
    public function runIds(): array
    {
        return $this->runIds;
    }

    public function hasArticles(): bool
    {
        return $this->articleIds !== [];
    }

    public function hasTasks(): bool
    {
        return $this->taskIds !== [];
    }

    public function hasRuns(): bool
    {
        return $this->runIds !== [];
    }

    public function queueDiskPath(string $path): void
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($normalized === '') {
            return;
        }

        $this->diskPathsToDelete[] = $normalized;
    }

    /**
     * @param  iterable<int, string|null>  $paths
     */
    public function queueDiskPaths(iterable $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path)) {
                $this->queueDiskPath($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function diskPathsToDelete(): array
    {
        return array_values(array_unique($this->diskPathsToDelete));
    }

    public function queueCacheLockKey(string $key): void
    {
        $normalized = trim($key);
        if ($normalized === '') {
            return;
        }

        $this->cacheLockKeys[] = $normalized;
    }

    /**
     * @return list<string>
     */
    public function cacheLockKeys(): array
    {
        return array_values(array_unique($this->cacheLockKeys));
    }

    public function bumpStat(string $key, int $by = 1): void
    {
        $this->stats[$key] = (int) ($this->stats[$key] ?? 0) + $by;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }
}
