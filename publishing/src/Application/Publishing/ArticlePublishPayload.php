<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

final class ArticlePublishPayload
{
    public function __construct(
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly ?int $wpPostId,
        public readonly string $externalReference,
        public readonly string $attemptRef,
        public readonly ?string $idempotencyKey = null,
        public readonly string $title = '',
        public readonly string $content = '',
        public readonly string $status = 'publish',
        public readonly int $projectId = 0,
        public readonly int $taskId = 0,
        public readonly ?int $actorUserId = null,
    ) {}
}
