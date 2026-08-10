<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Illuminate\Support\Str;

/**
 * Explicit user-initiated WordPress sync context (not Automation Rule match).
 */
final class ManualSyncContext
{
    public function __construct(
        public readonly int $initiatedBy,
        public readonly string $source,
        public readonly int $articleId,
        public readonly int $domainId,
        public readonly string $correlationId,
        public readonly string $requestId,
        public readonly string $requestedAt,
        public readonly bool $manual = true,
    ) {
        if ($this->initiatedBy <= 0) {
            throw new \InvalidArgumentException('initiated_by required.');
        }
        if ($this->source === '') {
            throw new \InvalidArgumentException('source required.');
        }
        if ($this->articleId <= 0) {
            throw new \InvalidArgumentException('article_id required.');
        }
    }

    public static function make(
        int $initiatedBy,
        string $source,
        int $articleId,
        int $domainId,
        ?string $correlationId = null,
        ?string $requestId = null,
    ): self {
        return new self(
            initiatedBy: $initiatedBy,
            source: $source,
            articleId: $articleId,
            domainId: $domainId,
            correlationId: $correlationId ?? (string) Str::uuid(),
            requestId: $requestId ?? (string) Str::uuid(),
            requestedAt: now()->toIso8601String(),
            manual: true,
        );
    }

    public function toSideEffectContext(string $reason = 'manual_sync'): ManualWordPressContext
    {
        return new ManualWordPressContext(
            userId: $this->initiatedBy,
            requestId: $this->requestId,
            articleId: $this->articleId,
            siteId: $this->domainId,
            reason: $reason !== '' ? $reason : 'manual_sync:'.$this->source,
            correlationId: $this->correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMeta(): array
    {
        return [
            'manual' => true,
            'initiated_by' => $this->initiatedBy,
            'source' => $this->source,
            'article_id' => $this->articleId,
            'domain_id' => $this->domainId,
            'site_id' => $this->domainId,
            'correlation_id' => $this->correlationId,
            'request_id' => $this->requestId,
            'requested_at' => $this->requestedAt,
        ];
    }
}
