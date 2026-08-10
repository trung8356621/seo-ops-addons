<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

/**
 * Result of reconciling local publishing item against WordPress evidence.
 */
final class PublishReconcileResult
{
    public const OUTCOME_PUBLISHED = 'published';

    public const OUTCOME_NOT_PUBLISHED = 'not_published';

    public const OUTCOME_IN_FLIGHT = 'in_flight';

    public const OUTCOME_UNKNOWN = 'unknown';

    public const CODE_WP_PUBLISHED_POST_NOT_FOUND = 'WP_PUBLISHED_POST_NOT_FOUND';

    public function __construct(
        public readonly string $outcome,
        public readonly ?int $wpPostId = null,
        public readonly ?string $permalink = null,
        public readonly ?string $remoteStatus = null,
        public readonly string $message = '',
        public readonly string $code = '',
    ) {}

    public function isPublished(): bool
    {
        return $this->outcome === self::OUTCOME_PUBLISHED
            && $this->wpPostId !== null
            && $this->wpPostId > 0;
    }

    public function isInFlight(): bool
    {
        return $this->outcome === self::OUTCOME_IN_FLIGHT;
    }
}
