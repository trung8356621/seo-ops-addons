<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;

interface ContentProjectCommandHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult;
}
