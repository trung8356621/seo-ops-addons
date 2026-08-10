<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleEditorHistoryLocalDraftIntervalTest extends TestCase
{
    public function test_default_local_draft_interval_is_two_seconds(): void
    {
        self::assertSame(2, ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS);
    }

    public function test_local_draft_interval_clamp_source_is_zero_to_thirty(): void
    {
        $ref = new ReflectionClass(ArticleEditorHistoryService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        // getSettings() + saveSettings() both clamp local draft interval (not DB autosave).
        self::assertSame(2, preg_match_all('/max\(0,\s*min\(30,\s*\$autosave\)\)/', $source));
    }
}
