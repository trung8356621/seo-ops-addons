<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Contracts;

use App\Models\Site;

interface DomainPromptContextFieldPatcher
{
    /**
     * @param  array{company_short_identity?: string, short_description?: string}  $patch
     */
    public function patchForSite(Site|int $site, array $patch): void;

    public function clampCompanyShortIdentity(string $value): string;

    public function countWords(string $text): int;
}
