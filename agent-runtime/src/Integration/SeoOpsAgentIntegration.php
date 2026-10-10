<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Integration;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;

final class SeoOpsAgentIntegration
{
    public static function registerCapabilities(): void
    {
        if (AgentCapabilityCatalog::known('content.topic_suggestions')) {
            return;
        }
        AgentCapabilityCatalog::register('content.topic_suggestions', [
            'label' => 'Topic Content Suggestions',
            'description' => 'Create content suggestions from weak topic coverage.',
            'jev_selectable' => true,
            'execution_mode' => 'tool',
            'requires_confirmation' => false,
            'status' => 'not_connected',
            'modules' => ['topics', 'keywords'],
        ]);
    }
}
