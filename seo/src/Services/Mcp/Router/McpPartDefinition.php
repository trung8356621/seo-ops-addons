<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

use Omnichannel\Addons\Seo\Services\Mcp\Support\McpSizeHint;

/**
 * AI-facing selectable unit within a router.
 * Context slice metadata (views/params/description) stays on ContextSliceDefinition.
 */
final class McpPartDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $contextKey,
        public readonly string $whenToUse,
        public readonly McpSizeHint $sizeHint = McpSizeHint::Medium,
        public readonly ?string $descriptionOverride = null,
    ) {}
}
