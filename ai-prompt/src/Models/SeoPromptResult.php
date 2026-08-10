<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

/**
 * Kết quả chạy prompt trên DB addon (`prompt_results`, connection `omi_seo_ai`).
 */
class SeoPromptResult extends PromptResult
{
    protected $table = 'prompt_results';
}
