<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use PHPUnit\Framework\TestCase;

final class ArticleEditorSyncPayloadOptionsTest extends TestCase
{
    public function test_prepare_payload_accepts_defer_inline_media_option(): void
    {
        $reflection = new \ReflectionClass(WordPressArticleSyncService::class);
        $method = $reflection->getMethod('buildEditorSyncPayload');

        $this->assertTrue($method->isPrivate());

        $prepare = $reflection->getMethod('prepareEditorSyncPayload');
        $parameters = $prepare->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame('syncOptions', $parameters[2]->getName());
    }

    public function test_complete_editor_sync_response_accepts_sync_options(): void
    {
        $reflection = new \ReflectionClass(WordPressArticleSyncService::class);
        $method = $reflection->getMethod('completeEditorSyncResponse');
        $parameters = $method->getParameters();

        $this->assertCount(4, $parameters);
        $this->assertSame('syncOptions', $parameters[3]->getName());
    }
}
