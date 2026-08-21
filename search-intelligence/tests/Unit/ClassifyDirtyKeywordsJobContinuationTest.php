<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Tests\TestCase;

final class ClassifyDirtyKeywordsJobContinuationTest extends TestCase
{
    public function test_runner_continues_until_dirty_remaining_is_zero(): void
    {
        $batches = [
            ['processed' => 500, 'dirty_remaining' => 120],
            ['processed' => 120, 'dirty_remaining' => 15],
            ['processed' => 15, 'dirty_remaining' => 0],
        ];
        $index = 0;
        $processed = 0;
        $loops = 0;

        do {
            $result = $batches[$index];
            $index++;
            $processed += (int) $result['processed'];
            $loops++;
        } while ($result['dirty_remaining'] > 0 && $result['processed'] > 0 && $loops < 200);

        self::assertSame(635, $processed);
        self::assertSame(3, $loops);
        self::assertSame(0, $result['dirty_remaining']);
    }
}
