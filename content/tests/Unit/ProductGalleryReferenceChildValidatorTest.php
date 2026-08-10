<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryReferenceChildValidator;
use PHPUnit\Framework\TestCase;

final class ProductGalleryReferenceChildValidatorTest extends TestCase
{
    public function test_missing_file_fails(): void
    {
        $result = (new ProductGalleryReferenceChildValidator)->validateLocalFile(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'does-not-exist-mode2-'.uniqid('', true).'.png',
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('file_unreadable', $result['errors']);
    }

    public function test_valid_square_image_passes(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pg_ref_child_'.uniqid('', true).'.png';
        $img = imagecreatetruecolor(512, 512);
        $bg = imagecolorallocate($img, 240, 240, 240);
        $fg = imagecolorallocate($img, 30, 90, 180);
        imagefilledrectangle($img, 0, 0, 511, 511, $bg);
        imagefilledrectangle($img, 80, 80, 400, 400, $fg);
        imagepng($img, $path);
        imagedestroy($img);

        try {
            $result = (new ProductGalleryReferenceChildValidator(minPx: 256))->validateLocalFile($path);
            $this->assertTrue($result['ok'], implode(',', $result['errors']));
        } finally {
            @unlink($path);
        }
    }

    public function test_too_small_rejected(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pg_ref_small_'.uniqid('', true).'.png';
        $img = imagecreatetruecolor(32, 32);
        imagepng($img, $path);
        imagedestroy($img);

        try {
            $result = (new ProductGalleryReferenceChildValidator(minPx: 256))->validateLocalFile($path);
            $this->assertFalse($result['ok']);
            $this->assertContains('too_small', $result['errors']);
        } finally {
            @unlink($path);
        }
    }

    public function test_duplicate_path_rejected(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD required');
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pg_ref_dup_'.uniqid('', true).'.png';
        $img = imagecreatetruecolor(400, 400);
        $fg = imagecolorallocate($img, 20, 20, 20);
        imagefilledrectangle($img, 10, 10, 200, 200, $fg);
        imagepng($img, $path);
        imagedestroy($img);

        try {
            $result = (new ProductGalleryReferenceChildValidator)->validateLocalFile($path, [$path]);
            $this->assertFalse($result['ok']);
            $this->assertContains('duplicate_of_existing_child', $result['errors']);
        } finally {
            @unlink($path);
        }
    }
}
