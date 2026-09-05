<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\AiModel;

/**
 * Compatibility alias — canonical model is App\Models\AiModel.
 *
 * Kept so existing AiPrompt / SEO call sites continue resolving without a big-bang rewrite.
 */
class SeoAiModel extends AiModel
{
}
