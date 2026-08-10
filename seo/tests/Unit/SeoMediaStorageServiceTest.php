<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoImageOptimizationService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoMediaStorageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $optimization = $this->createMock(SeoImageOptimizationService::class);
        $optimization->method('resolveForSite')->willReturn(new SeoImageOptimizationSetting([
            'auto_convert_webp' => false,
            'quality' => 80,
            'limit_dimensions' => false,
            'clean_filename' => false,
            'auto_alt_tag' => false,
        ]));
        $optimization->method('processUpload')->willReturnCallback(function (UploadedFile $file): array {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
            $slug = 'img-test-' . random_int(100, 999);
            $filename = $slug . '.' . $extension;
            $relativePath = "uploads/seo_media/{$filename}";

            return [
                'slug' => $slug,
                'filename' => $filename,
                'relative_path' => $relativePath,
                'alt_text' => $slug,
                'binary' => (string) file_get_contents($file->getRealPath()),
            ];
        });

        $this->app->instance(SeoImageOptimizationService::class, $optimization);
    }

    public function test_store_upload_creates_file_and_record(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('paste.png', 120, 80);
        $media = app(SeoMediaStorageService::class)->storeUpload($file, null, null, 'clipboard');

        $this->assertInstanceOf(SeoMedia::class, $media);
        $this->assertSame('clipboard', $media->source);

        Storage::disk('public')->assertExists($media->path);
        $this->assertStringStartsWith('uploads/seo_media/', $media->path);
        $this->assertStringStartsWith('/storage/uploads/seo_media/', $media->url);

        $media->delete();
    }

    public function test_rename_by_slug_moves_file_on_disk(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('clip.jpg');
        $media = app(SeoMediaStorageService::class)->storeUpload($file, null, null, 'clipboard');
        $oldPath = $media->path;

        $renamed = app(SeoMediaStorageService::class)->renameBySlug($media, 'my-seo-image');

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($renamed->path);
        $this->assertSame('my-seo-image', $renamed->slug);
        $this->assertStringEndsWith('my-seo-image.jpg', $renamed->filename);

        $renamed->delete();
    }
}
