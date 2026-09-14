<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * How local inventory treats provider discovery results.
 */
enum AiModelCatalogAuthorityMode: string
{
    /** Successful discovery is sole truth; absent models become inactive. */
    case Provider = 'provider';

    /** Discovery + curated compatibility layer; curated cannot resurrect removed models. */
    case Hybrid = 'hybrid';

    /** No usable discovery — static/curated registry is authority (must be labeled). */
    case Curated = 'curated';
}
