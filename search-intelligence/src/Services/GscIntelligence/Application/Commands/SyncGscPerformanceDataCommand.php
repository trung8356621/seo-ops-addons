<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class SyncGscPerformanceDataCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?string $providerKey = null,
        public readonly array $options = []
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.sync_performance';
    }
}
