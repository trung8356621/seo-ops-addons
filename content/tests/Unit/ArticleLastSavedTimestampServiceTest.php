<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleLastSavedTimestampServiceTest extends TestCase
{
    private ArticleLastSavedTimestampService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ArticleLastSavedTimestampService;
    }

    public function test_manual_only(): void
    {
        $resolved = $this->service->resolve([
            'last_manual_saved_at' => '2026-07-23 21:35:00',
            'last_synced_at' => null,
        ]);

        self::assertSame('manual', $resolved['source']);
        self::assertSame('Lưu thủ công', $resolved['source_label']);
        self::assertNotSame('—', $resolved['display']);
    }

    public function test_sync_only(): void
    {
        $resolved = $this->service->resolve([
            'last_manual_saved_at' => null,
            'last_synced_at' => '2026-07-22 16:10:00',
        ]);

        self::assertSame('sync', $resolved['source']);
        self::assertSame('Đồng bộ', $resolved['source_label']);
    }

    public function test_manual_newer_than_sync(): void
    {
        $resolved = $this->service->resolve([
            'last_manual_saved_at' => Carbon::parse('2026-07-23 21:35:00'),
            'last_synced_at' => Carbon::parse('2026-07-22 16:10:00'),
        ]);

        self::assertSame('manual', $resolved['source']);
    }

    public function test_sync_newer_than_manual(): void
    {
        $resolved = $this->service->resolve([
            'last_manual_saved_at' => Carbon::parse('2026-07-22 16:10:00'),
            'last_synced_at' => Carbon::parse('2026-07-23 21:35:00'),
        ]);

        self::assertSame('sync', $resolved['source']);
    }

    public function test_neither_shows_dash(): void
    {
        $resolved = $this->service->resolve([
            'last_manual_saved_at' => null,
            'last_synced_at' => null,
        ]);

        self::assertNull($resolved['source']);
        self::assertSame('—', $resolved['display']);
    }

    public function test_only_article_editor_origin_is_manual(): void
    {
        self::assertTrue($this->service->shouldTouchManualFromOrigin('article_editor'));
        self::assertFalse($this->service->shouldTouchManualFromOrigin('manual_wordpress_sync'));
        self::assertFalse($this->service->shouldTouchManualFromOrigin('migration.project_article_content_update'));
        self::assertFalse($this->service->shouldTouchManualFromOrigin(null));
    }
}
