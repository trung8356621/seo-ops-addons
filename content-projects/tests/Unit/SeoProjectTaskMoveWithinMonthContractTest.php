<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Pure (no-DB) contract: within-month move is writer-agnostic and site-agnostic at project level.
 */
final class SeoProjectTaskMoveWithinMonthContractTest extends TestCase
{
    public function test_move_target_options_are_month_scoped_without_site_filter(): void
    {
        $move = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName(),
        );

        $optionsMethod = new ReflectionMethod(SeoProjectTaskMoveService::class, 'moveTargetOptions');
        $optionsSrc = $this->readMethodSource($optionsMethod);

        self::assertStringContainsString('whereKeyNot($source->getKey())', $optionsSrc);
        self::assertStringContainsString("whereNull('archived_at')", $optionsSrc);
        self::assertStringContainsString("where('status', '!=', SeoProject::STATUS_DRAFT)", $optionsSrc);
        self::assertStringContainsString("whereDate('month', \$monthDate)", $optionsSrc);
        self::assertStringContainsString('targetHasPackingRoom', $optionsSrc);
        self::assertStringContainsString('writerLabelForMoveOption', $optionsSrc);

        // No project-level same-domain discovery.
        self::assertStringNotContainsString('resolveMoveDomainSiteIds', $optionsSrc);
        self::assertStringNotContainsString('resolveProjectItemSiteIds', $optionsSrc);
        self::assertStringNotContainsString('ContentProjectTenantGuard', $optionsSrc);
        self::assertStringNotContainsString("where('site_id'", $optionsSrc);
        self::assertStringNotContainsString('whereIn(\'site_id\'', $optionsSrc);
        self::assertStringNotContainsString("where('user_id'", $optionsSrc);

        $moveMethod = new ReflectionMethod(SeoProjectTaskMoveService::class, 'moveTasksToProject');
        $moveSrc = $this->readMethodSource($moveMethod);
        self::assertStringNotContainsString('assertTasksShareTargetDomain', $moveSrc);
        self::assertStringNotContainsString('move_domain_mismatch', $moveSrc);
        self::assertStringContainsString('move_month_mismatch', $moveSrc);
        self::assertStringContainsString('assertTargetAcceptsMoves', $moveSrc);
        self::assertStringContainsString('assertTargetHasPackingSlots', $moveSrc);
        self::assertStringContainsString('assertMoveRespectsWriterCapacity', $moveSrc);
        self::assertStringContainsString('appendTasksToProject', $moveSrc);

        $packingMethod = new ReflectionMethod(SeoProjectTaskMoveService::class, 'targetHasPackingRoom');
        $packingSrc = $this->readMethodSource($packingMethod);
        self::assertStringContainsString('isArchive()', $packingSrc);
        self::assertStringContainsString('MAX_EXECUTION_PROJECT_ITEMS', $packingSrc);
        self::assertStringContainsString('MAX_EXECUTION_PROJECT_ITEMS', $move);

        $append = new ReflectionMethod(SeoProjectTaskMoveService::class, 'appendTasksToProject');
        $appendSrc = $this->readMethodSource($append);
        self::assertStringContainsString("'project_id'", $appendSrc);
        self::assertStringContainsString("'target_date'", $appendSrc);
        self::assertStringNotContainsString("'site_id'", $appendSrc);
        self::assertStringNotContainsString("'status'", $appendSrc);
        self::assertStringNotContainsString("'article_id'", $appendSrc);

        self::assertStringContainsString('throwUnlessProjectCanAccept', $move);
        self::assertStringNotContainsString('resolveMoveDomainSiteIds', $move);
        self::assertStringNotContainsString('assertTasksShareTargetDomain', $move);
    }

    public function test_move_modal_locale_is_within_month_and_mentions_other_writers(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        self::assertStringContainsString("'move_task_heading' => 'Move item within month'", $en);
        self::assertStringContainsString("'move_task_heading' => 'Chuyển hạng mục trong cùng tháng'", $vi);
        self::assertStringContainsString('different writer', $en);
        self::assertStringContainsString('writer khác', $vi);
        self::assertStringContainsString(':writer', $en);
        self::assertStringContainsString(':writer', $vi);
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
