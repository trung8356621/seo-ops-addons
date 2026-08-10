<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Article;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use RuntimeException;

final class ArticleContentConflictException extends RuntimeException
{
    public function __construct(public readonly ActionResult $result)
    {
        parent::__construct((string) ($result->error['message'] ?? 'content conflict'));
    }
}
