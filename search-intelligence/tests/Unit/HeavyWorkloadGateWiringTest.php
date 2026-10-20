<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use App\Core\Performance\HeavyWorkloadMiddleware;
use App\Core\Performance\OptionalBackgroundHeavy;
use Omnichannel\Addons\SearchIntelligence\Jobs\ProcessGscUrlInspectionRunJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RebuildTopicsFromKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RunKeywordRankCheckBatchJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\SyncGscSiteSnapshotJob;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HeavyWorkloadGateWiringTest extends TestCase
{
    public function test_protected_jobs_register_heavy_middleware(): void
    {
        $cases = [
            RefreshKeywordGroupsJob::class => [1, true],
            RebuildTopicsFromKeywordGroupsJob::class => [1, null],
            RunKeywordRankCheckBatchJob::class => [null, null],
            SyncGscSiteSnapshotJob::class => [1, 1],
            ProcessGscUrlInspectionRunJob::class => [1, null],
        ];

        foreach ($cases as $class => [$siteOrRun, $userOrBg]) {
            $job = $this->makeJob($class, $siteOrRun, $userOrBg);
            self::assertTrue(method_exists($job, 'middleware'), $class);
            $middleware = $job->middleware();
            $types = array_map(static fn (object $m): string => $m::class, $middleware);
            self::assertContains(HeavyWorkloadMiddleware::class, $types, $class);
        }

        $wp = new ReflectionClass(ManualWordPressSyncJob::class);
        self::assertTrue($wp->hasMethod('middleware'));
        $src = (string) file_get_contents((string) $wp->getFileName());
        self::assertStringContainsString('HeavyWorkloadMiddleware', $src);
    }

    public function test_gsc_and_keyword_refresh_support_optional_background_flag(): void
    {
        $gsc = new SyncGscSiteSnapshotJob(1, 2, true);
        self::assertInstanceOf(OptionalBackgroundHeavy::class, $gsc);
        self::assertTrue($gsc->isOptionalBackgroundHeavy());

        $refresh = new RefreshKeywordGroupsJob(9, optionalBackground: true);
        self::assertTrue($refresh->isOptionalBackgroundHeavy());
        self::assertFalse((new RefreshKeywordGroupsJob(9))->isOptionalBackgroundHeavy());
    }

    private function makeJob(string $class, mixed $a, mixed $b): object
    {
        return match ($class) {
            RefreshKeywordGroupsJob::class => new RefreshKeywordGroupsJob((int) $a),
            RebuildTopicsFromKeywordGroupsJob::class => new RebuildTopicsFromKeywordGroupsJob((int) $a),
            RunKeywordRankCheckBatchJob::class => new RunKeywordRankCheckBatchJob(1, 1, 'dataforseo', [], 'hash'),
            SyncGscSiteSnapshotJob::class => new SyncGscSiteSnapshotJob((int) $a, (int) $b),
            ProcessGscUrlInspectionRunJob::class => new ProcessGscUrlInspectionRunJob((int) $a),
            default => throw new \InvalidArgumentException($class),
        };
    }
}
