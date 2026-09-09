<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use RuntimeException;

/**
 * @deprecated Retired experimental V2 site-scoped persistence.
 * Use SeedingSharedTopicService / SeedingReportService.
 */
final class SeedingTopicService
{
    public function __call(string $name, array $arguments): mixed
    {
        throw new RuntimeException(
            'SeedingTopicService is retired. Use SeedingSharedTopicService / SeedingReportService.'
        );
    }
}
