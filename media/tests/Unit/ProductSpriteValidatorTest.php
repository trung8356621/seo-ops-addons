<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryImageDeduper;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductSpriteValidator;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductSpriteValidationResult;
use Omnichannel\Addons\Media\Support\ProductGallery\SplitResult;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use PHPUnit\Framework\TestCase;

final class ProductSpriteValidatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seo_pg_mode1_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_hard_fail_when_file_missing(): void
    {
        $validator = new ProductSpriteValidator([
            'confidence_threshold' => 0.8,
            'min_canvas_px' => 256,
        ]);

        $result = $validator->validate($this->tempDir.DIRECTORY_SEPARATOR.'missing.png', 3);

        $this->assertTrue($result->hardFailed);
        $this->assertFalse($result->valid);
        $this->assertSame(0.0, $result->confidence);
        $this->assertContains('sprite_unreadable', $result->reasonCodes);
        $this->assertFalse($result->passesThreshold(0.8));
    }

    public function test_hard_fail_non_square_canvas(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = $this->writeSolidPng(1536, 1024);
        $result = (new ProductSpriteValidator(['confidence_threshold' => 0.8, 'min_canvas_px' => 256]))
            ->validate($path, 3);

        $this->assertTrue($result->hardFailed);
        $this->assertFalse($result->passesThreshold(0.8));
    }

    public function test_hard_fail_not_divisible(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = $this->writeSolidPng(1000, 1000);
        $result = (new ProductSpriteValidator(['confidence_threshold' => 0.8, 'min_canvas_px' => 256]))
            ->validate($path, 3);

        $this->assertTrue($result->hardFailed);
    }

    public function test_pass_valid_square_grid_with_gutters(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = $this->writeGridSprite(1536, 3);
        $result = (new ProductSpriteValidator([
            'confidence_threshold' => 0.8,
            'min_canvas_px' => 256,
        ]))->validate($path, 3);

        $this->assertFalse($result->hardFailed);
        $this->assertSame(3, $result->expectedGrid);
        $this->assertSame(9, $result->detectedPanels);
        $this->assertCount(9, $result->rectangles);
        $this->assertGreaterThan(0.5, $result->confidence);
        $this->assertContains($result->splitStrategy, [
            SpriteValidationResult::STRATEGY_FIXED_GRID,
            SpriteValidationResult::STRATEGY_NONE,
        ]);
    }

    public function test_soft_checks_only_reduce_confidence_not_hard_fail(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = $this->writeSolidPng(1536, 1536, 40, 40, 40);
        $result = (new ProductSpriteValidator([
            'confidence_threshold' => 0.99,
            'min_canvas_px' => 256,
        ]))->validate($path, 3);

        $this->assertFalse($result->hardFailed);
        $this->assertSame([], $result->hardFailures);
        $this->assertFalse($result->passesThreshold(0.99));
        $this->assertLessThan(0.99, $result->confidence);
    }

    public function test_ready_state_normalize_maps_to_original_images(): void
    {
        $vars = ProductGalleryReadyState::mergeIntoVariables([], [
            'gallery_ready' => true,
            'gallery_source' => 'original_fallback',
            'fallback_snapshot' => ProductGalleryReadyState::buildFallbackSnapshot([
                ['id' => 11, 'url' => 'https://example.test/a.jpg'],
            ], ProductGalleryReadyState::ORIGIN_ALBUM_BEFORE_GENERATE),
            'child_media_ids' => [1, 2],
        ]);

        $state = ProductGalleryReadyState::readFromVariables($vars);

        $this->assertTrue($state['gallery_ready']);
        $this->assertSame(ProductGallerySource::OriginalImages->value, $state['gallery_source']);
        $this->assertSame([11], $state['fallback_snapshot']['media_ids']);
    }

    public function test_split_result_failed_contract(): void
    {
        $failed = SplitResult::failed('nope', 'X');
        $this->assertFalse($failed->success);
        $this->assertSame('X', $failed->errorCode);
    }

    public function test_deduper_by_url_and_id(): void
    {
        $out = (new ProductGalleryImageDeduper)->dedupe([
            ['id' => 1, 'url' => 'https://cdn.test/a.jpg?x=1'],
            ['id' => 1, 'url' => 'https://cdn.test/a.jpg?x=2'],
            ['id' => 2, 'url' => 'https://cdn.test/a.jpg'],
            ['id' => 3, 'url' => 'https://cdn.test/b.jpg'],
        ]);

        $this->assertCount(2, $out);
    }

    public function test_product_sprite_validation_result_alias(): void
    {
        $result = ProductSpriteValidationResult::hardFail('x', 3, ['a'], threshold: 0.8);
        $this->assertInstanceOf(SpriteValidationResult::class, $result);
        $this->assertTrue($result->hardFailed);
    }

    public function test_validation_result_array_roundtrip(): void
    {
        $original = new SpriteValidationResult(
            valid: true,
            hardFailed: false,
            confidence: 0.91,
            threshold: 0.8,
            reason: 'PASS',
            expectedGrid: 3,
            detectedPanels: 9,
            rectangles: [['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'row' => 0, 'col' => 0]],
            softFlags: ['whitespace'],
            softScores: ['whitespace' => 0.8],
            canvasWidth: 1536,
            canvasHeight: 1536,
            splitStrategy: SpriteValidationResult::STRATEGY_FIXED_GRID,
        );

        $roundtrip = SpriteValidationResult::fromArray($original->toArray());

        $this->assertTrue($roundtrip->valid);
        $this->assertFalse($roundtrip->hardFailed);
        $this->assertSame(0.91, $roundtrip->confidence);
        $this->assertSame(SpriteValidationResult::STRATEGY_FIXED_GRID, $roundtrip->splitStrategy);
    }

    private function writeSolidPng(
        int $width,
        int $height,
        int $r = 255,
        int $g = 255,
        int $b = 255,
    ): string {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'solid_'.$width.'x'.$height.'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function writeGridSprite(int $size, int $grid): string
    {
        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $panel = imagecolorallocate($image, 30, 120, 200);
        imagefilledrectangle($image, 0, 0, $size, $size, $white);

        $cell = intdiv($size, $grid);
        $pad = max(2, intdiv($cell, 16));
        for ($row = 0; $row < $grid; $row++) {
            for ($col = 0; $col < $grid; $col++) {
                $x = $col * $cell + $pad;
                $y = $row * $cell + $pad;
                $w = $cell - (2 * $pad);
                $h = $cell - (2 * $pad);
                imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $panel);
            }
        }

        $path = $this->tempDir.DIRECTORY_SEPARATOR."grid_{$grid}.png";
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
