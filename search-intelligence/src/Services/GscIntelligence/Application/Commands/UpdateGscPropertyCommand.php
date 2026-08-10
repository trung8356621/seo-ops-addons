<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class UpdateGscPropertyCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly array $attributes
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.update_property';
    }
}
