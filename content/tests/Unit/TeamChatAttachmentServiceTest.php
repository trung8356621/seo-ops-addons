<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TeamChatAttachmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_store_rejects_disallowed_extension(): void
    {
        $service = new TeamChatAttachmentService(SeoOverviewSettingsService::withDefaults());
        $file = UploadedFile::fake()->create('report.exe', 20, 'application/octet-stream');

        $this->expectException(ValidationException::class);

        $service->store($file, 7);
    }

    public function test_store_saves_allowed_png_file(): void
    {
        $overview = SeoOverviewSettingsService::withDefaults();
        $service = new TeamChatAttachmentService($overview);
        $file = UploadedFile::fake()->image('screenshot.png');

        $stored = $service->store($file, 7);

        $this->assertTrue($stored['is_image']);
        $this->assertSame('screenshot.png', $stored['name']);
        Storage::disk('public')->assertExists($stored['path']);
    }
}
