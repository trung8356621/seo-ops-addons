<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum ConfigurationPackageType: string
{
    case AiProviderTemplate = 'ai_provider_template';
    case SeoSettings = 'seo_settings';
    case PromptPack = 'prompt_pack';
    case SeoConfigurationBundle = 'seo_configuration_bundle';
}
