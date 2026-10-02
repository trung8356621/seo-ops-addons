<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts;

interface GlobalMatchRuleProvider
{
    /** @return array<string, list<string>> */
    public function globalMatchRules(): array;
}
