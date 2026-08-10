<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Support\LegacyProjectRunItemMapper;
use PHPUnit\Framework\TestCase;

final class LegacyProjectRunItemMapperTest extends TestCase
{
    public function test_maps_success_new_keyword_item(): void
    {
        $mapped = (new LegacyProjectRunItemMapper)->map([
            'task_id' => 11,
            'type' => 'new_keyword',
            'source_content' => 'abc',
            'post_type' => 'article',
            'status' => 'success',
            'article_id' => 99,
            'message' => 'ok',
            'last_run_at' => '2026-07-18 10:00:00',
            'retry_count' => 2,
            'steps' => [['type' => 'action']],
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame(11, $mapped['task_id']);
        $this->assertSame(99, $mapped['article_id']);
        $this->assertSame(SeoProjectRunAction::ArticleCreate->value, $mapped['action']);
        $this->assertSame(SeoProjectRunItemStatus::Success->value, $mapped['status']);
        $this->assertSame(3, $mapped['attempt']);
        $this->assertSame('2026-07-18 10:00:00', $mapped['finished_at']);
        $this->assertSame('abc', $mapped['input_snapshot']['source_content']);
    }

    public function test_maps_failed_rewrite_and_manual(): void
    {
        $mapper = new LegacyProjectRunItemMapper;

        $failed = $mapper->map([
            'task_id' => 1,
            'type' => 'rewrite',
            'status' => 'failed',
            'error_detail' => 'boom',
        ]);
        $this->assertSame(SeoProjectRunAction::ArticleRewrite->value, $failed['action']);
        $this->assertSame(SeoProjectRunItemStatus::Failed->value, $failed['status']);
        $this->assertSame('legacy_failed', $failed['error_code']);
        $this->assertSame('boom', $failed['error_message']);

        $manual = $mapper->map([
            'task_id' => 2,
            'type' => 'improve',
            'status' => 'manual',
        ]);
        $this->assertSame(SeoProjectRunAction::ArticleUpdate->value, $manual['action']);
        $this->assertSame(SeoProjectRunItemStatus::Manual->value, $manual['status']);
    }

    public function test_empty_item_returns_null(): void
    {
        $this->assertNull((new LegacyProjectRunItemMapper)->map([]));
    }
}
