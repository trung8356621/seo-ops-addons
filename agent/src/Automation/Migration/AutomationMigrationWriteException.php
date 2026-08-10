<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use RuntimeException;

final class AutomationMigrationWriteException extends RuntimeException
{
    public function __construct(
        public readonly string $callerKey,
        string $message,
        public readonly ActionResult $result,
    ) {
        parent::__construct("[{$callerKey}] {$message}");
    }
}
