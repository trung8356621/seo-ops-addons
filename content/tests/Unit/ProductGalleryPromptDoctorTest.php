<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Media\Console\InstallDefaultProductGalleryPromptsCommand;
use Omnichannel\Addons\Media\Console\ProductGalleryPromptsDoctorCommand;
use Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPlanParser;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptsDoctorService;
use PHPUnit\Framework\TestCase;

final class ProductGalleryPromptDoctorTest extends TestCase
{
    public function test_doctor_and_install_commands_exist(): void
    {
        $this->assertTrue(class_exists(ProductGalleryPromptsDoctorCommand::class));
        $this->assertTrue(class_exists(InstallDefaultProductGalleryPromptsCommand::class));
        $this->assertTrue(class_exists(ProductGalleryPromptsDoctorService::class));

        $doctor = new \ReflectionClass(ProductGalleryPromptsDoctorCommand::class);
        $props = $doctor->getDefaultProperties();
        $this->assertSame('seo:product-gallery-prompts-doctor', $props['signature'] ?? null);

        $install = new \ReflectionClass(InstallDefaultProductGalleryPromptsCommand::class);
        $installProps = $install->getDefaultProperties();
        $this->assertSame('seo:prompt:install-default-product-gallery', $installProps['signature'] ?? null);
    }

    public function test_doctor_checks_fallback_brief_disabled_flag(): void
    {
        $this->assertFalse(GeminiProductGalleryParentChildAiAdapter::FALLBACK_BRIEF_ENABLED);

        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/commerce/src/Services/ProductGallery/ProductGalleryPromptsDoctorService.php',
        );
        $this->assertStringContainsString('FALLBACK_BRIEF_ENABLED', $source);
        $this->assertStringContainsString('fallback brief', $source);
        $this->assertStringContainsString('runtime compile', $source);
    }

    public function test_doctor_service_lists_three_hooks(): void
    {
        $this->assertSame([
            'product.gallery.plan',
            'product.gallery.parent.generate',
            'product.gallery.child.generate',
        ], ProductGalleryPromptsDoctorService::HOOKS);
    }

    public function test_planner_contract_rejects_invalid_priority(): void
    {
        $parser = new ProductGalleryPlanParser(['1:1', '4:3'], 6);
        $bad = $parser->parse(
            '{"shots":[{"slot":1,"shot_key":"front","label":"A","priority":"nope","aspect_ratio":"1:1","instruction":"x"}]}',
            3,
        );
        $this->assertFalse($bad['ok']);

        $ok = $parser->parse(
            '{"shots":[{"slot":1,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"front"}]}',
            3,
        );
        $this->assertTrue($ok['ok']);
    }

    public function test_provider_registers_doctor_command(): void
    {
        $source = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );
        $this->assertStringContainsString('ProductGalleryPromptsDoctorCommand::class', $source);
        $this->assertStringContainsString('InstallDefaultProductGalleryPromptsCommand::class', $source);
    }
}
