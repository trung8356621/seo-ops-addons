<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Contracts;

interface WordPressFieldSyncAccessChecker
{
    public function canSync(\App\Models\Site $site): bool;
}
