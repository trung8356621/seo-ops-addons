<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Media\Console\ProductGalleryCanaryFixtureCommand;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryCleanupService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryFixtureService;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryCanaryFixtureTest extends TestCase
{
    public function test_default_payload_is_product_ready(): void
    {
        $payload = ProductGalleryCanaryFixtureService::defaultProductPayload();
        $this->assertSame('Túi đeo chéo màu đen Urban Mini', $payload['title']);
        $this->assertSame('túi đeo chéo màu đen', $payload['keyword']);
        $this->assertNotSame('', $payload['description']);
        $this->assertSame('Demo Brand', $payload['brand']);
        $this->assertContains('không đổi màu', $payload['negative_constraints']);
        $this->assertSame('vi', $payload['language']);
    }

    public function test_input_requirements_split_required_optional(): void
    {
        $req = ProductGalleryCanaryFixtureService::inputRequirements();
        $this->assertArrayHasKey('post_type', $req['required']);
        $this->assertArrayHasKey('original_media', $req['required']);
        $this->assertArrayHasKey('mode1_prompt_binding', $req['required']);
        $this->assertArrayHasKey('article body', $req['optional']);
        $this->assertArrayHasKey('mode2_bindings', $req['optional']);
    }

    public function test_fixture_service_uses_production_writers_not_raw_sql(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryFixtureService::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('SeoProjectTaskUniqueWriter', $source);
        $this->assertStringContainsString('appendProductAlbumLocal', $source);
        $this->assertStringContainsString('POST_TYPE_PRODUCT', $source);
        $this->assertStringContainsString('is_canary', $source);
        $this->assertStringContainsString('canary_type', $source);
        $this->assertStringNotContainsString('DB::statement', $source);
        $this->assertStringNotContainsString('INSERT INTO', $source);
    }

    public function test_cleanup_keeps_originals_and_marks_discarded(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryCleanupService::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString(ProductGalleryArtifactRole::ORIGINAL, $source);
        $this->assertStringContainsString('canary_discarded', $source);
        $this->assertStringContainsString('archived_canary', $source);
        $this->assertStringContainsString('isCanaryArticle', $source);
        $this->assertStringNotContainsString('->delete()', $source);
    }

    public function test_feature_auto_allows_fixture_articles(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryParentChildFeature::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('auto_allow_fixture_articles', $source);
        $this->assertStringContainsString('ProductGalleryCanaryAccess::isCanaryArticle', $source);
    }

    public function test_artisan_command_registered_signature(): void
    {
        $props = (new ReflectionClass(ProductGalleryCanaryFixtureCommand::class))->getDefaultProperties();
        $signature = (string) ($props['signature'] ?? '');
        $this->assertStringContainsString('seo:product-gallery-canary-fixture', $signature);
        $this->assertStringContainsString('--site=', $signature);
        $this->assertStringContainsString('--media=', $signature);

        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );
        $this->assertStringContainsString('ProductGalleryCanaryFixtureCommand::class', $provider);
    }
}
