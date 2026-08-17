<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use PHPUnit\Framework\TestCase;

final class ImageRegressionContractTest extends TestCase
{
    public function test_ai_center_ux_does_not_import_image_runtime(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsAiCenter::class))->getFileName());
        $presenter = (string) file_get_contents((new \ReflectionClass(AiCenterModelPresenter::class))->getFileName());
        $this->assertStringNotContainsString('ImageRoutingStrategy', $page);
        $this->assertStringNotContainsString('ImageOutputModePromptInjector', $page);
        $this->assertStringNotContainsString('ImageRoutingStrategy', $presenter);

        $strategy = (string) file_get_contents((new \ReflectionClass(ImageRoutingStrategy::class))->getFileName());
        $injector = (string) file_get_contents((new \ReflectionClass(ImageOutputModePromptInjector::class))->getFileName());
        $this->assertStringNotContainsString('SeoSettingsAiCenter', $strategy);
        $this->assertStringNotContainsString('AiCenterModelPresenter', $injector);
        $this->assertStringNotContainsString('tableRows', $strategy);
    }
}
