<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Tests\TestCase;

final class ArticleWriteLockFailFastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::clear();
    }

    public function test_lock_for_article_a_does_not_block_article_b(): void
    {
        $articleALock = Cache::lock(ActionSupport::articleWriteLockKey(2754), 30);
        self::assertTrue($articleALock->get());

        try {
            self::assertSame(
                'article-b-saved',
                ActionSupport::withArticleLock(2383, static fn (): string => 'article-b-saved'),
            );
        } finally {
            $articleALock->release();
        }
    }

    public function test_same_article_conflict_returns_without_waiting(): void
    {
        $heldLock = Cache::lock(ActionSupport::articleWriteLockKey(2754), 30);
        self::assertTrue($heldLock->get());
        $startedAt = hrtime(true);

        try {
            ActionSupport::withArticleLock(2754, static fn (): bool => true);
            self::fail('Concurrent write should fail fast.');
        } catch (\RuntimeException $exception) {
            self::assertSame('article_write_busy', $exception->getMessage());
            self::assertLessThan(250, (hrtime(true) - $startedAt) / 1_000_000);
        } finally {
            $heldLock->release();
        }
    }
}
