<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

/**
 * Only valid manual origin for WordPress mutating requests.
 */
final class ManualWordPressContext implements WordPressExecutionContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $requestId,
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly string $reason,
        public readonly string $correlationId,
    ) {
        if ($this->userId <= 0) {
            throw new \InvalidArgumentException('user_id required for manual WordPress context.');
        }
        if ($this->requestId === '') {
            throw new \InvalidArgumentException('request_id required for manual WordPress context.');
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
        return 'manual';
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
        return $this->userId;
    }

    /**
     * @return array{
     *     manual: true,
     *     initiated_by: int,
     *     user_id: int,
     *     article_id: int,
     *     site_id: int,
     *     reason: string,
     *     request_id: string,
     *     correlation_id: string
     * }
     */
    public function toAuditMeta(): array
    {
        return [
            'manual' => true,
            'initiated_by' => $this->userId,
            'user_id' => $this->userId,
            'article_id' => $this->articleId,
            'site_id' => $this->siteId,
            'reason' => $this->reason,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ];
    }
}
