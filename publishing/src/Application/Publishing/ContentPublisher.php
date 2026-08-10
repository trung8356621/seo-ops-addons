<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

interface ContentPublisher
{
    public function publish(ArticlePublishPayload $payload): PublishResult;

    public function findByExternalReference(int $siteId, string $externalReference): ?int;
}
