<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Pure (no-DB) contract for within-month move eligibility + list Edit removal.
 * DB-backed cases live in {@see SeoProjectTaskMoveWithinMonthTest}.
 */
final class SeoProjectTaskMoveWithinMonthContractTest extends TestCase
{
    public function test_move_target_options_require_same_month_site_and_exclude_source_archive(): void
    {
        $move = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName(),
        );

        $optionsMethod = new ReflectionMethod(SeoProjectTaskMoveService::class, 'moveTargetOptions');
        $optionsSrc = $this->readMethodSource($optionsMethod);

        self::assertStringContainsString("where('site_id', \$siteId)", $optionsSrc);
        self::assertStringContainsString('whereKeyNot($source->getKey())', $optionsSrc);
        self::assertStringContainsString("whereNull('archived_at')", $optionsSrc);
        self::assertStringContainsString("whereDate('month', \$monthDate)", $optionsSrc);
        self::assertStringContainsString('isArchive()', $optionsSrc);
        self::assertStringContainsString('writerLabelForMoveOption', $optionsSrc);
        // Must not filter targets by source writer.
        self::assertStringNotContainsString("where('user_id'", $optionsSrc);
        self::assertStringNotContainsString('orderByDesc(\'month\')', $optionsSrc);

        $moveMethod = new ReflectionMethod(SeoProjectTaskMoveService::class, 'moveTasksToProject');
        $moveSrc = $this->readMethodSource($moveMethod);
        self::assertStringContainsString('move_domain_mismatch', $moveSrc);
        self::assertStringContainsString('move_month_mismatch', $moveSrc);
        self::assertStringContainsString('move_same_project', $moveSrc);
        self::assertStringContainsString('assertTargetAcceptsMoves', $moveSrc);
        self::assertStringContainsString('assertMoveRespectsWriterCapacity', $moveSrc);
        self::assertStringContainsString('appendTasksToProject', $moveSrc);
        self::assertStringContainsString('syncProjectArticles', $moveSrc);

        $append = new ReflectionMethod(SeoProjectTaskMoveService::class, 'appendTasksToProject');
        $appendSrc = $this->readMethodSource($append);
        self::assertStringContainsString("'project_id'", $appendSrc);
        self::assertStringContainsString("'target_date'", $appendSrc);
        self::assertStringNotContainsString("'status'", $appendSrc);
        self::assertStringNotContainsString("'article_id'", $appendSrc);
        self::assertStringNotContainsString('planning_reviewed', $appendSrc);

        $accepts = new ReflectionMethod(SeoProjectTaskMoveService::class, 'assertTargetAcceptsMoves');
        $acceptsSrc = $this->readMethodSource($accepts);
        self::assertStringContainsString('isArchive()', $acceptsSrc);
        self::assertStringContainsString('isProjectArchived()', $acceptsSrc);

        // Capacity: same-writer+same-month remains capacity-neutral; cross-writer still gated.
        self::assertStringContainsString('throwUnlessProjectCanAccept', $move);
        self::assertStringContainsString('$sameWriter', $move);
        self::assertStringContainsString('isSamePlanningMonth', $move);
    }

    public function test_move_modal_locale_is_within_month_and_mentions_other_writers(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        self::assertStringContainsString("'move_task_heading' => 'Move item within month'", $en);
        self::assertStringContainsString("'move_task_heading' => 'Chuyển hạng mục trong cùng tháng'", $vi);
        self::assertStringContainsString('different writer', $en);
        self::assertStringContainsString('writer khác', $vi);
        self::assertStringContainsString("'move_target_option_items' => ':name (:month) — :writer — :count items'", $en);
        self::assertStringContainsString("'move_target_option_items' => ':name (:month) — :writer — :count items'", $vi);
        self::assertStringContainsString('move_month_mismatch', $en);
        self::assertStringContainsString('move_month_mismatch', $vi);
        self::assertStringNotContainsString('Move item to another month', $en);
        self::assertStringNotContainsString('sang tháng khác', $vi);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        self::assertNotFalse($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
