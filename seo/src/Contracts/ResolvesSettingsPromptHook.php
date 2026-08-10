<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Contracts;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

interface ResolvesSettingsPromptHook
{
    public function resolveSettingsHook(string $hookKey): SeoPrompt;
}
