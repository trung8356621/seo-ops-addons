<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\Media;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Omnichannel\Addons\Media\Models\SeoMedia;

final class MediaAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'media';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            BusinessEventName::MediaUploaded,
            BusinessEventName::MediaProcessed,
            BusinessEventName::MediaFailed,
        ] as $enum) {
            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: SeoMedia::class,
                payloadSchema: [
                    'media_id' => ['type' => 'mixed', 'required' => true],
                    'site_id' => ['type' => 'mixed', 'required' => false],
                ],
                description: $enum->value,
                module: 'media',
            ));
        }
    }
}
