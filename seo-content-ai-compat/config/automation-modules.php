<?php

declare(strict_types=1);

return [
    'modules' => [
        \Omnichannel\Addons\Agent\Automation\Modules\Core\CoreAutomationModuleProvider::class => true,
        \Omnichannel\Addons\Agent\Automation\Modules\WordPress\WordPressAutomationModuleProvider::class => true,
        \Omnichannel\Addons\Agent\Automation\Modules\Content\ContentAutomationModuleProvider::class => true,
        \Omnichannel\Addons\Agent\Automation\Modules\Seo\SeoAutomationModuleProvider::class => true,
        \Omnichannel\Addons\Agent\Automation\Modules\Media\MediaAutomationModuleProvider::class => true,
        \Omnichannel\Addons\Agent\Automation\Modules\Sample\SampleAutomationModuleProvider::class => false,
    ],
];
