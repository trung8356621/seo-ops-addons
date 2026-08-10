<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use InvalidArgumentException;

/**
 * Registry cleaner — module mới đăng ký thêm tại ServiceProvider.
 */
final class ContentProjectWorkspaceCleanupRegistry
{
    /** @var array<string, ContentProjectWorkspaceCleaner> */
    private array $cleaners = [];

    /**
     * @param  iterable<int, ContentProjectWorkspaceCleaner>  $cleaners
     */
    public function __construct(iterable $cleaners = [])
    {
        foreach ($cleaners as $cleaner) {
            $this->register($cleaner);
        }
    }

    public function register(ContentProjectWorkspaceCleaner $cleaner): void
    {
        $key = $cleaner->key();
        if ($key === '') {
            throw new InvalidArgumentException('Workspace cleaner key must not be empty.');
        }

        $this->cleaners[$key] = $cleaner;
    }

    /**
     * @return list<ContentProjectWorkspaceCleaner>
     */
    public function all(): array
    {
        return array_values($this->cleaners);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->cleaners);
    }
}
