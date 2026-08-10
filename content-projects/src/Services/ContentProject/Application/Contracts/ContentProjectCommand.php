<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts;

interface ContentProjectCommand
{
    public function name(): string;
}
