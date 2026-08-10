<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Services\SeoMediaPathAllocator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoMediaPathAllocatorTest extends TestCase
{
    public function test_allocate_uses_flat_directory(): void
    {
        Storage::fake('public');

        $first = app(SeoMediaPathAllocator::class)->allocate('phong-cach-minimalist', 'jpg');
        Storage::disk('public')->put($first['relative_path'], 'binary');

        $this->assertSame('uploads/seo_media/phong-cach-minimalist.jpg', $first['relative_path']);

        $second = app(SeoMediaPathAllocator::class)->allocate('phong-cach-minimalist', 'jpg');
        $this->assertSame('uploads/seo_media/phong-cach-minimalist-1.jpg', $second['relative_path']);
    }
}
