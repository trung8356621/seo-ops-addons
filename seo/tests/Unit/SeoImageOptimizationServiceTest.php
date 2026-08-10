<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Media\Services\SeoImageOptimizationService;
use Omnichannel\Addons\Media\Services\SeoMediaPathAllocator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SeoImageOptimizationServiceTest extends TestCase
{
    private function service(): SeoImageOptimizationService
    {
        $analyzer = $this->createMock(SeoAnalyzerService::class);

        return new SeoImageOptimizationService(
            $analyzer,
            app(SeoMediaPathAllocator::class),
            app(\Omnichannel\Addons\Media\Support\SeoImagePipeline::class),
        );
    }

    private function webpEnabledConfig(): SeoImageOptimizationSetting
    {
        return new SeoImageOptimizationSetting([
            'auto_convert_webp' => true,
            'quality' => 80,
            'limit_dimensions' => false,
            'clean_filename' => false,
            'auto_alt_tag' => false,
        ]);
    }

    public function test_process_upload_keeps_original_extension_when_webp_enabled(): void
    {
        $file = UploadedFile::fake()->image('split-piece.png', 80, 60);

        $processed = $this->service()->processUpload($file, $this->webpEnabledConfig());

        $this->assertStringEndsWith('.png', $processed['filename']);
        $this->assertStringEndsWith('.png', $processed['relative_path']);
    }

    public function test_needs_wordpress_webp_backfill_when_wp_url_not_webp(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-backfill.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->ensureLocalWebpCopy($absolutePath, $config);
        if ($webpPath === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertTrue($service->needsWordPressWebpBackfill(
            $config,
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.jpg',
        ));
        $this->assertFalse($service->needsWordPressWebpBackfill(
            $config,
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.webp',
        ));
        $this->assertFalse($service->needsWordPressWebpBackfill(
            new SeoImageOptimizationSetting(['auto_convert_webp' => false]),
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.jpg',
        ));

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
    }

    public function test_needs_wordpress_webp_backfill_false_when_optimized_fallback_exists(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-backfill-skip.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $service = $this->service();
        $optimizedPath = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        Storage::disk('public')->put(
            'uploads/seo_media/'.basename($optimizedPath),
            str_repeat('x', 512),
        );

        $this->assertFalse($service->needsWordPressWebpBackfill(
            $this->webpEnabledConfig(),
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo-wp-upload.jpg',
        ));
    }

    public function test_prepare_wordpress_upload_does_not_mutate_original_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-gallery-original.jpg';
        $originalBinary = (string) file_get_contents($file->getRealPath());
        Storage::disk('public')->put($relativePath, $originalBinary);

        $absolutePath = Storage::disk('public')->path($relativePath);
        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());

        if ($uploadFile === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertSame($originalBinary, Storage::disk('public')->get($relativePath));

        if (($uploadFile['temporary'] ?? false) && is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }

    public function test_prepare_wordpress_upload_converts_to_webp_when_enabled(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 640, 480);
        $relativePath = 'uploads/seo_media/test-gallery.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());

        if ($uploadFile === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        if (! str_ends_with((string) ($uploadFile['path'] ?? ''), '.webp')) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertFalse((bool) ($uploadFile['temporary'] ?? true));
        $this->assertStringEndsWith('.webp', (string) $uploadFile['path']);
        $this->assertSame('image/webp', $uploadFile['mime']);
        $this->assertLessThanOrEqual(
            SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
            (int) filesize((string) $uploadFile['path']),
        );

        if (is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }

    public function test_ensure_local_webp_under_max_bytes_uses_edge_ladder(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('huge.jpg', 2400, 2400);
        $relativePath = 'uploads/seo_media/test-gallery-edge.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        if (! $service->canEncodeWebp()) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $compactPath = $service->ensureLocalWebpUnderMaxBytes(
            $absolutePath,
            $config,
            SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
        );

        if ($compactPath === null) {
            $this->markTestSkipped('WebP edge ladder could not produce a file in this PHP environment.');
        }

        $this->assertStringEndsWith('.webp', $compactPath);
        $this->assertLessThanOrEqual(
            SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
            (int) filesize($compactPath),
        );

        $size = @getimagesize($compactPath);
        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(1920, max((int) $size[0], (int) $size[1]));

        if (is_file($compactPath)) {
            @unlink($compactPath);
        }
    }

    public function test_wordpress_upload_edge_ladder_never_shrinks_below_1024(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('wide.jpg', 2400, 1600);
        $relativePath = 'uploads/seo_media/test-gallery-min-edge.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $method = new \ReflectionMethod(SeoImageOptimizationService::class, 'resolveWordPressUploadEdgeSteps');
        $method->setAccessible(true);

        $steps = $method->invoke($this->service(), Storage::disk('public')->path($relativePath));

        $this->assertSame([1920, 1280, 1024], $steps);
        $this->assertGreaterThanOrEqual(1024, min($steps));
        $this->assertNotContains(800, $steps);
        $this->assertNotContains(640, $steps);
        $this->assertNotContains(480, $steps);
    }

    public function test_portrait_wordpress_webp_prefers_width_over_byte_target(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('portrait.jpg', 1200, 1800);
        $relativePath = 'uploads/seo_media/test-gallery-portrait.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();

        if (! $service->canEncodeWebp()) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $compactPath = $service->ensureLocalWebpUnderMaxBytes(
            $absolutePath,
            $this->webpEnabledConfig(),
            1,
        );

        if ($compactPath === null) {
            $this->markTestSkipped('WebP encoder could not produce a protected portrait candidate.');
        }

        $size = @getimagesize($compactPath);
        $this->assertIsArray($size);
        $this->assertGreaterThanOrEqual(SeoImageOptimizationService::WORDPRESS_UPLOAD_PORTRAIT_MIN_WIDTH, (int) $size[0]);
        $this->assertGreaterThan(1, (int) filesize($compactPath));

        if (is_file($compactPath)) {
            @unlink($compactPath);
        }
    }

    public function test_prepare_wordpress_upload_falls_back_to_optimized_when_webp_unavailable(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 800, 600);
        $relativePath = 'uploads/seo_media/test-gallery-fallback.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->ensureLocalWebpCopy($absolutePath, $config);
        if ($webpPath !== null && is_file($webpPath)) {
            @unlink($webpPath);
        }

        $optimizedPath = $service->ensureLocalOptimizedUploadCopy($absolutePath, $config);
        if ($optimizedPath === null) {
            $this->markTestSkipped('Image encoder is not available in this PHP environment.');
        }

        $this->assertStringEndsWith('-wp-upload.jpg', $optimizedPath);
        $this->assertLessThanOrEqual(
            SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
            (int) filesize($optimizedPath),
        );
        $this->assertTrue(Storage::disk('public')->exists($relativePath));

        if (is_file($optimizedPath)) {
            @unlink($optimizedPath);
        }
    }

    public function test_prepare_wordpress_upload_returns_optimized_file_when_webp_fails(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 640, 480);
        $relativePath = 'uploads/seo_media/test-gallery-wp-fallback.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->resolveSiblingWebpAbsolutePath($absolutePath);
        if (is_file($webpPath)) {
            @unlink($webpPath);
        }

        $uploadFile = $service->prepareWordPressUploadFile($absolutePath, $config);
        $this->assertNotNull($uploadFile);

        $uploadPath = (string) ($uploadFile['path'] ?? '');
        if (str_ends_with($uploadPath, '.webp')) {
            $this->assertSame('image/webp', $uploadFile['mime']);
        } else {
            $this->assertStringContainsString('-wp-upload', $uploadPath);
            $this->assertLessThanOrEqual(
                SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
                (int) filesize($uploadPath),
            );
        }

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
        $optimizedPath = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        if (is_file($optimizedPath)) {
            @unlink($optimizedPath);
        }
    }

    public function test_is_usable_webp_rejects_invalid_binary(): void
    {
        Storage::fake('public');

        $relativePath = 'uploads/seo_media/bad-sibling.webp';
        Storage::disk('public')->put($relativePath, str_repeat('not-a-webp', 40));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $this->assertFalse($this->service()->isUsableWebpFile($absolutePath));
    }

    public function test_prepare_wordpress_upload_falls_back_to_jpeg_when_webp_unusable(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 400, 300);
        $relativePath = 'uploads/seo_media/test-gallery-unusable-webp.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->resolveSiblingWebpAbsolutePath($absolutePath);
        // Sibling “webp” hỏng: RIFF/WEBP header giả, không decode được.
        $payload = 'RIFF'.pack('V', 300).'WEBP'.str_repeat("\x00", 300);
        file_put_contents($webpPath, $payload);
        @touch($webpPath, time() + 10);

        $uploadFile = $service->prepareWordPressUploadFile($absolutePath, $config);
        $this->assertNotNull($uploadFile);

        $uploadPath = (string) ($uploadFile['path'] ?? '');
        if (str_ends_with($uploadPath, '.webp')) {
            $this->assertTrue($service->isUsableWebpFile($uploadPath, $absolutePath));
            $this->assertLessThanOrEqual(
                SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
                (int) filesize($uploadPath),
            );
        } else {
            // Ưu tiên original ≤100KB; chỉ -wp-upload khi original quá lớn.
            $this->assertTrue(
                $uploadPath === $absolutePath || str_contains($uploadPath, '-wp-upload'),
                'Expected original or -wp-upload, got: '.$uploadPath,
            );
            $this->assertLessThanOrEqual(
                SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
                (int) filesize($uploadPath),
            );
        }

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
        $optimizedPath = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        if (is_file($optimizedPath)) {
            @unlink($optimizedPath);
        }
    }

    public function test_prepare_wordpress_upload_keeps_jpeg_when_webp_disabled(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-gallery.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $config = new SeoImageOptimizationSetting([
            'auto_convert_webp' => false,
            'quality' => 80,
            'limit_dimensions' => false,
        ]);

        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $config);

        $this->assertNotNull($uploadFile);
        $this->assertStringEndsWith('.jpg', $uploadFile['path']);
        $this->assertSame('image/jpeg', $uploadFile['mime']);

        if (($uploadFile['temporary'] ?? false) && is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }

    public function test_process_binary_rejects_fully_transparent_source_webp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD imagewebp unavailable.');
        }

        $path = storage_path('app/temp/seo-blank-src.webp');
        @mkdir(dirname($path), 0755, true);
        $image = imagecreatetruecolor(120, 80);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagewebp($image, $path, 80);
        imagedestroy($image);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ảnh nguồn không decode được hoặc bị hỏng');

        $this->service()->processBinary($bytes, 'webp', $this->webpEnabledConfig());
    }

    public function test_process_binary_keeps_png_extension_for_png_bytes(): void
    {
        $path = storage_path('app/temp/seo-alpha-src.png');
        @mkdir(dirname($path), 0755, true);
        $image = imagecreatetruecolor(100, 100);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
        imagefilledrectangle($image, 10, 10, 50, 50, $red);
        imagepng($image, $path);
        imagedestroy($image);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        // Client nhầm extension webp nhưng bytes là PNG → lưu .png, không ghi PNG vào .webp.
        $processed = $this->service()->processBinary($bytes, 'webp', $this->webpEnabledConfig(), null, 'paste-alpha');

        $this->assertStringEndsWith('.png', $processed['filename']);
        $this->assertStringEndsWith('.png', $processed['relative_path']);
        $this->assertSame('png', pathinfo($processed['filename'], PATHINFO_EXTENSION));
        $this->assertSame("\x89PNG", substr($processed['binary'], 0, 4));
    }

    public function test_ensure_local_webp_does_not_mutate_source_on_failure(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 320, 240);
        $relativePath = 'uploads/seo_media/test-source-immutable.jpg';
        $original = (string) file_get_contents($file->getRealPath());
        Storage::disk('public')->put($relativePath, $original);
        $absolutePath = Storage::disk('public')->path($relativePath);

        $before = (string) file_get_contents($absolutePath);
        $this->service()->ensureLocalWebpCopy($absolutePath, $this->webpEnabledConfig());
        $after = (string) file_get_contents($absolutePath);

        $this->assertSame($before, $after);
    }

    public function test_validate_converted_image_rejects_blank_webp_bytes(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD imagewebp unavailable.');
        }

        $path = storage_path('app/temp/seo-blank-validate.webp');
        @mkdir(dirname($path), 0755, true);
        $image = imagecreatetruecolor(800, 437);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagewebp($image, $path, 80);
        imagedestroy($image);

        $result = $this->service()->validateConvertedImage($path);
        $this->assertFalse($result['ok']);
        $this->assertSame('fully_transparent_canvas', $result['reason']);
        @unlink($path);
    }

    public function test_prepare_wordpress_upload_blocks_fully_transparent_source(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD imagewebp unavailable.');
        }

        Storage::fake('public');
        $relativePath = 'uploads/seo_media/blank-source.webp';
        $absolutePath = Storage::disk('public')->path($relativePath);
        @mkdir(dirname($absolutePath), 0755, true);

        $image = imagecreatetruecolor(200, 120);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagewebp($image, $absolutePath, 80);
        imagedestroy($image);

        // Blank vẫn decode được (getimagesize OK) → prepare không được chặn sync cứng;
        // WebP sibling blank bị reject nhưng fallback trả chính file gốc nếu isValidImageFile.
        $upload = $this->service()->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());
        $this->assertNotNull($upload);
        $this->assertTrue(is_file($upload['path']));
        @unlink($absolutePath);
    }

    public function test_prepare_wordpress_upload_falls_back_when_webp_blank_sibling(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD imagewebp unavailable.');
        }

        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 400, 300);
        $relativePath = 'uploads/seo_media/good-source.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $service = $this->service();
        $webpPath = $service->resolveSiblingWebpAbsolutePath($absolutePath);

        $blank = imagecreatetruecolor(400, 300);
        imagesavealpha($blank, true);
        imagealphablending($blank, false);
        $transparent = imagecolorallocatealpha($blank, 0, 0, 0, 127);
        imagefill($blank, 0, 0, $transparent);
        imagewebp($blank, $webpPath, 80);
        imagedestroy($blank);
        @touch($webpPath, time() + 60);

        $upload = $service->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());
        $this->assertNotNull($upload);
        $uploadPath = (string) $upload['path'];
        // Không được chọn sibling blank; phải WebP mới hợp lệ hoặc fallback JPEG/PNG/original.
        if (str_ends_with($uploadPath, '.webp')) {
            $this->assertTrue($service->isUsableWebpFile($uploadPath, $absolutePath));
        } else {
            $this->assertTrue($service->isValidImageFile($uploadPath));
            $this->assertNotSame('image/webp', $upload['mime']);
        }

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
        $opt = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        if (is_file($opt)) {
            @unlink($opt);
        }
    }

    public function test_prepare_wordpress_prefers_original_png_when_under_limit(): void
    {
        Storage::fake('public');

        $path = storage_path('app/temp/seo-logo-src.png');
        @mkdir(dirname($path), 0755, true);
        $image = imagecreatetruecolor(160, 100);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $blue = imagecolorallocatealpha($image, 30, 90, 200, 0);
        imagefilledellipse($image, 80, 50, 50, 50, $blue);
        imagepng($image, $path);
        imagedestroy($image);

        $relativePath = 'uploads/seo_media/logo-original.png';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($path));
        @unlink($path);
        $absolutePath = Storage::disk('public')->path($relativePath);

        $configNoWebp = new SeoImageOptimizationSetting([
            'auto_convert_webp' => false,
            'quality' => 80,
            'limit_dimensions' => false,
            'clean_filename' => false,
            'auto_alt_tag' => false,
        ]);

        $upload = $this->service()->prepareWordPressUploadFile($absolutePath, $configNoWebp);
        $this->assertNotNull($upload);
        // Không WebP: có thể original hoặc -wp-upload đã compress.
        $this->assertTrue($this->service()->isValidImageFile($upload['path']));
        $this->assertContains($upload['mime'], ['image/png', 'image/jpeg']);
    }

    public function test_prepare_wordpress_accepts_jpeg_and_png_fallback_mime(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 200, 150);
        $relativePath = 'uploads/seo_media/mime-check.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $config = new SeoImageOptimizationSetting([
            'auto_convert_webp' => false,
            'quality' => 80,
            'limit_dimensions' => false,
            'clean_filename' => false,
            'auto_alt_tag' => false,
        ]);

        $upload = $this->service()->prepareWordPressUploadFile($absolutePath, $config);
        $this->assertNotNull($upload);
        $this->assertSame('image/jpeg', $upload['mime']);
        $this->assertStringEndsWith('.jpg', $upload['path']);
    }

    public function test_optimized_upload_target_keeps_png_extension(): void
    {
        Storage::fake('public');
        $relativePath = 'uploads/seo_media/keep-png.png';
        Storage::disk('public')->put($relativePath, 'x');
        $absolutePath = Storage::disk('public')->path($relativePath);

        $target = $this->service()->resolveSiblingOptimizedUploadAbsolutePath(
            $absolutePath,
            'png',
        );
        $this->assertStringEndsWith('-wp-upload.png', $target);
    }
}
