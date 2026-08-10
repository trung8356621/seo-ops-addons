<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use RuntimeException;

final class ContentProjectWorkspaceSaveException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'persist_failed',
    ) {
        parent::__construct($message);
    }
}
