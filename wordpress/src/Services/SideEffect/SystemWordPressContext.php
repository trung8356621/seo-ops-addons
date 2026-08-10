<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

/**
 * System/scheduler origin — read-only WordPress reconcile probes only.
 */
final class SystemWordPressContext implements WordPressExecutionContext
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly string $reason,
        public readonly string $correlationId,
    ) {
        if ($this->requestId === '') {
            throw new \InvalidArgumentException('request_id required for system WordPress context.');
        }
        if ($this->articleId <= 0) {
            throw new \InvalidArgumentException('article_id required.');
        }
        if ($this->reason === '') {
            throw new \InvalidArgumentException('reason required.');
        }
    }

    public function origin(): string
    {
        return 'system';
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function articleId(): ?int
    {
        return $this->articleId;
    }

    public function siteId(): ?int
    {
        return $this->siteId > 0 ? $this->siteId : null;
    }

    public function actorId(): ?int
    {
        return null;
    }
}
