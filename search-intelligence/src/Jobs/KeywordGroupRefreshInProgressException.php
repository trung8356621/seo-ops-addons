<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use RuntimeException;

/** Synchronous refresh found another run still holding the overlap lock. */
final class KeywordGroupRefreshInProgressException extends RuntimeException
{
}
