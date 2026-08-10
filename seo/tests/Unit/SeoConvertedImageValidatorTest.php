<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Support\ImageContentSignatureSampler;
use Omnichannel\Addons\Media\Support\SeoConvertedImageValidator;
use Tests\TestCase;

final class SeoConvertedImageValidatorTest extends TestCase
{
    private function validator(): SeoConvertedImageValidator
    {
        return new SeoConvertedImageValidator;
    }

    private function tempPath(string $extension): string
    {
        $dir = storage_path('app/temp/seo-image-validate-tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.uniqid('img-', true).'.'.$extension;
    }

    private function writeOpaqueJpeg(string $path, int $width = 120, int $height = 80): void
    {
        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 220, 40, 40);
        $blue = imagecolorallocate($image, 40, 80, 220);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, $red);
        imagefilledrectangle($image, (int) ($width / 2), 0, $width, $height, $blue);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function writeSolidColorJpeg(string $path, int $r, int $g, int $b, int $width = 100, int $height = 80): void
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function writePngWithVisibleAlpha(string $path, int $width = 100, int $height = 100): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
        imagefilledrectangle($image, 10, 10, 60, 60, $red);
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function writeFullyTransparentWebp(string $path, int $width = 800, int $height = 437): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD imagewebp unavailable.');
        }

        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagewebp($image, $path, 80);
        imagedestroy($image);
    }

    private function writeTinyLogoPng(string $path): void
    {
        $image = imagecreatetruecolor(120, 80);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $blue = imagecolorallocatealpha($image, 20, 80, 200, 0);
        imagefilledellipse($image, 60, 40, 40, 40, $blue);
        imagepng($image, $path);
        imagedestroy($image);
    }

    public function test_rejects_fully_transparent_webp(): void
    {
        $path = $this->tempPath('webp');
        $this->writeFullyTransparentWebp($path, 200, 120);

        $result = $this->validator()->validate($path);
        $this->assertFalse($result['ok']);
        $this->assertSame(SeoConvertedImageValidator::REASON_FULLY_TRANSPARENT_CANVAS, $result['reason']);

        @unlink($path);
    }

    public function test_accepts_png_with_visible_alpha_content(): void
    {
        $path = $this->tempPath('png');
        $this->writePngWithVisibleAlpha($path);

        $result = $this->validator()->validate($path);
        $this->assertTrue($result['ok'], $result['reason']);
        $this->assertGreaterThan(0, $result['width']);

        @unlink($path);
    }

    public function test_accepts_small_logo_on_transparent_png(): void
    {
        $path = $this->tempPath('png');
        $this->writeTinyLogoPng($path);

        $result = $this->validator()->validate($path);
        $this->assertTrue($result['ok'], $result['reason']);

        @unlink($path);
    }

    public function test_accepts_normal_jpeg(): void
    {
        $path = $this->tempPath('jpg');
        $this->writeOpaqueJpeg($path);

        $result = $this->validator()->validate($path);
        $this->assertTrue($result['ok'], $result['reason']);

        @unlink($path);
    }

    public function test_accepts_legitimate_solid_color_source(): void
    {
        $path = $this->tempPath('jpg');
        $this->writeSolidColorJpeg($path, 0, 0, 0);

        $result = $this->validator()->validate($path);
        $this->assertTrue($result['ok'], 'Solid black source alone must not be rejected: '.$result['reason']);

        @unlink($path);
    }

    public function test_rejects_uniform_black_output_when_source_has_variance(): void
    {
        $source = $this->tempPath('jpg');
        $output = $this->tempPath('jpg');
        $this->writeOpaqueJpeg($source);
        $this->writeSolidColorJpeg($output, 0, 0, 0, 120, 80);

        $result = $this->validator()->validate($output, ['source_path' => $source]);
        $this->assertFalse($result['ok']);
        $this->assertContains($result['reason'], [
            SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED,
            SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED_UNIFORM,
        ]);

        @unlink($source);
        @unlink($output);
    }

    public function test_rejects_uniform_white_output_when_source_has_variance(): void
    {
        $source = $this->tempPath('jpg');
        $output = $this->tempPath('jpg');
        $this->writeOpaqueJpeg($source);
        $this->writeSolidColorJpeg($output, 255, 255, 255, 120, 80);

        $result = $this->validator()->validate($output, ['source_path' => $source]);
        $this->assertFalse($result['ok']);
        $this->assertContains($result['reason'], [
            SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED,
            SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED_UNIFORM,
        ]);

        @unlink($source);
        @unlink($output);
    }

    public function test_detects_extension_from_bytes(): void
    {
        $pngPath = $this->tempPath('png');
        $this->writePngWithVisibleAlpha($pngPath);
        $bytes = (string) file_get_contents($pngPath);
        @unlink($pngPath);

        $this->assertSame('png', $this->validator()->detectImageExtensionFromBytes($bytes));
    }

    public function test_rejects_dimension_mismatch(): void
    {
        $path = $this->tempPath('jpg');
        $this->writeOpaqueJpeg($path, 100, 50);

        $result = $this->validator()->validate($path, [
            'expected_width' => 200,
            'expected_height' => 100,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(SeoConvertedImageValidator::REASON_DIMENSION_MISMATCH, $result['reason']);

        @unlink($path);
    }

    public function test_empty_canvas_vs_visible_source(): void
    {
        $source = $this->tempPath('png');
        $blank = $this->tempPath('webp');
        $this->writePngWithVisibleAlpha($source);
        $this->writeFullyTransparentWebp($blank, 100, 100);

        $result = $this->validator()->validate($blank, ['source_path' => $source]);
        $this->assertFalse($result['ok']);
        $this->assertContains($result['reason'], [
            SeoConvertedImageValidator::REASON_FULLY_TRANSPARENT_CANVAS,
            SeoConvertedImageValidator::REASON_CONTENT_COLLAPSED,
        ]);

        @unlink($source);
        @unlink($blank);
    }

    public function test_signature_sampler_sees_logo_pixels(): void
    {
        $path = $this->tempPath('png');
        $this->writeTinyLogoPng($path);
        $signature = (new ImageContentSignatureSampler)->fromPath($path);
        $this->assertNotNull($signature);
        $this->assertTrue($signature->hasVisibleContent());
        $this->assertFalse($signature->fullyTransparent);

        @unlink($path);
    }
}
