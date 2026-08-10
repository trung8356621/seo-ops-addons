<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

/**
 * Only valid automation origin for WordPress mutating requests.
 */
final class AutomationWordPressContext implements WordPressExecutionContext
{
    public function __construct(
        public readonly int $automationExecutionId,
        public readonly ?int $automationNodeExecutionId,
        public readonly string $businessEventUuid,
        public readonly string $idempotencyKey,
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly string $correlationId,
    ) {
        if ($this->automationExecutionId <= 0) {
            throw new \InvalidArgumentException('automation_execution_id required.');
        }
        if ($this->businessEventUuid === '' || $this->idempotencyKey === '') {
            throw new \InvalidArgumentException('business_event_uuid and idempotency_key required.');
        }
        if ($this->articleId <= 0) {
            throw new \InvalidArgumentException('article_id required.');
        }
    }

    public function origin(): string
    {
        return 'automation';
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
